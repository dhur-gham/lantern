<?php

namespace Lantern\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Lantern\Storage\MigrationGuard;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class InstallCommand extends Command
{
    protected $signature = 'lantern:install {--force : Overwrite published files}
        {--local : Append the env lines needed to record and read traces locally}
        {--sql : Print the migration SQL instead of installing (touches nothing)}
        {--connection= : With --sql, the connection whose driver the SQL is written for}
        {--since= : With --sql, only migrations first shipped in this version or later (for example 0.4.0)}';

    protected $description = 'Publish Lantern config and migrations, or print the migration SQL (--sql)';

    /** Version that first shipped each migration; a migration not listed here counts as the newest. */
    private const SHIPPED_IN = [
        '2024_01_01_000001_create_lantern_traces_table' => '0.1.0',
        '2024_01_01_000002_create_lantern_issues_table' => '0.1.0',
        '2024_01_01_000003_add_app_to_lantern_tables' => '0.4.0',
        '2024_01_01_000004_create_lantern_plan_samples_table' => '0.4.0',
        '2024_01_01_000005_add_sql_hash_to_lantern_plan_samples_table' => '0.4.1',
        '2024_01_01_000006_add_resolved_state_to_lantern_issues_table' => '0.4.2',
        '2024_01_01_000007_add_site_hash_to_lantern_issues_table' => '0.5.1',
    ];

    /** Migrations whose change a create migration already includes, so printing both would add the column twice. */
    private const FOLDED_INTO_CREATE = [
        '2024_01_01_000003_add_app_to_lantern_tables' => '2024_01_01_000001_create_lantern_traces_table',
    ];

    public function handle(): int
    {
        if ($this->option('sql')) {
            return $this->printSql();
        }
        if ($this->option('since') !== null || $this->option('connection') !== null) {
            $this->error('--since and --connection only work together with --sql.');

            return 2;
        }

        $force = (bool) $this->option('force');

        $this->call('vendor:publish', ['--tag' => 'lantern-config', '--force' => $force]);
        if ($force || ! $this->migrationsPublished()) {
            $this->call('vendor:publish', ['--tag' => 'lantern-migrations', '--force' => $force]);
        }

        if ($this->option('local')) {
            $this->writeLocalEnv();
        }

        $this->info($this->option('local') ? 'Lantern installed.' : 'Lantern installed. Set LANTERN_ENABLED=true to enable it.');

        $this->line('Running your test suite? Add <env name="LANTERN_DB_CONNECTION" value=""/> to phpunit.xml so migrate and RefreshDatabase use the testing database, not the real one (Lantern migrations on another connection are skipped under tests).');

        if (MigrationGuard::lastSkip() === 'connection-unverified') {
            $this->warn('A Lantern migration was skipped because its connection could not be verified; the tables may not exist. Check lantern.storage.connection, then run php artisan migrate.');
        }

        return self::SUCCESS;
    }

    private function migrationsPublished(): bool
    {
        return glob(database_path('migrations').'/*_create_lantern_traces_table.php') !== [] && glob(database_path('migrations').'/*_create_lantern_traces_table.php') !== false;
    }

    private function writeLocalEnv(): void
    {
        $path = base_path('.env');
        $env = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = ['LANTERN_ENABLED' => 'true', 'LANTERN_STORAGE_DRIVER' => 'database', 'LANTERN_KEEP_RATE' => '1'];

        $changed = [];
        foreach ($lines as $key => $value) {
            if (preg_match('/^'.$key.'='.preg_quote($value, '/').'\s*$/m', $env) === 1) {
                continue;
            }
            $changed[] = $key;
            if (preg_match('/^'.$key.'=/m', $env) === 1) {
                $env = (string) preg_replace('/^'.$key.'=.*$/m', $key.'='.$value, $env);
            } else {
                $env = rtrim($env, "\n").($env === '' ? '' : "\n").$key.'='.$value."\n";
            }
        }

        if ($changed === []) {
            $this->line('.env already has LANTERN_ENABLED, LANTERN_STORAGE_DRIVER and LANTERN_KEEP_RATE set.');

            return;
        }

        file_put_contents($path, $env);
        $this->line('Set in .env: '.implode(', ', $changed).'. Already present: '.(implode(', ', array_diff(array_keys($lines), $changed)) ?: 'none').'. Run php artisan migrate.');
    }

    private function printSql(): int
    {
        $since = $this->option('since');
        if ($since !== null && preg_match('/^\d+(\.\d+){0,2}$/', (string) $since) !== 1) {
            $this->getOutput()->getErrorStyle()->error('Invalid --since: '.$since.'. Use a version such as 0.4.0.');

            return 2;
        }

        $name = $this->option('connection');
        $name = is_string($name) && $name !== '' ? $name : null;
        $name ??= is_string(config('lantern.storage.connection')) && config('lantern.storage.connection') !== '' ? config('lantern.storage.connection') : null;

        try {
            $connection = DB::connection($name);
            $driver = $connection->getDriverName();
        } catch (Throwable $e) {
            $this->getOutput()->getErrorStyle()->error('Cannot use connection '.($name ?? '(default)').': '.$e->getMessage());

            return 2;
        }

        $files = glob(__DIR__.'/../../database/migrations/*.php') ?: [];
        sort($files);
        $this->raw('-- Lantern migration SQL for '.$driver.' (connection '.($name ?? 'default').')');

        $previous = config('lantern.storage.connection');
        config(['lantern.storage.connection' => $name]);
        $failed = false;
        try {
            foreach ($files as $file) {
                $migration = basename($file, '.php');
                if (! $this->included($migration, $since)) {
                    continue;
                }

                $this->raw('', '-- '.$migration);
                $folded = self::FOLDED_INTO_CREATE[$migration] ?? null;
                if ($folded !== null && $this->included($folded, $since)) {
                    $this->raw('-- (already part of the create statements above)');

                    continue;
                }

                try {
                    $instance = require $file;
                    if (! $instance instanceof Migration) {
                        continue;
                    }
                    $statements = $connection->pretend(fn () => $instance->up());
                } catch (Throwable $e) {
                    $failed = true;
                    $this->raw('-- skipped: could not generate SQL');
                    $this->getOutput()->getErrorStyle()->writeln($migration.': '.$e->getMessage());

                    continue;
                }

                // Pretend mode also records the existence checks a migration runs; only schema changes belong in the script.
                $statements = array_values(array_filter($statements, fn (array $s): bool => preg_match('/^\s*(select|pragma|show|describe)\b/i', (string) $s['query']) !== 1));
                if ($statements === []) {
                    $this->raw('-- (no statements)');
                }
                foreach ($statements as $statement) {
                    $this->raw(rtrim((string) $statement['query'], "; \n").';');
                }
            }
        } finally {
            config(['lantern.storage.connection' => $previous]);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function included(string $migration, mixed $since): bool
    {
        return $since === null || version_compare(self::SHIPPED_IN[$migration] ?? '999.0.0', (string) $since, '>=');
    }

    private function raw(string ...$lines): void
    {
        foreach ($lines as $line) {
            $this->getOutput()->write($line."\n", false, OutputInterface::OUTPUT_RAW);
        }
    }
}
