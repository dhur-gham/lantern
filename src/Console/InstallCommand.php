<?php

namespace Lantern\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'lantern:install {--force : Overwrite published files}
        {--local : Append the env lines needed to record and read traces locally}';

    protected $description = 'Publish Lantern config and migrations';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->call('vendor:publish', ['--tag' => 'lantern-config', '--force' => $force]);
        $this->call('vendor:publish', ['--tag' => 'lantern-migrations', '--force' => $force]);

        if ($this->option('local')) {
            $this->writeLocalEnv();
        }

        $this->info('Lantern installed. Set LANTERN_ENABLED=true to enable it.');

        return self::SUCCESS;
    }

    private function writeLocalEnv(): void
    {
        $path = base_path('.env');
        $env = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = ['LANTERN_ENABLED' => 'true', 'LANTERN_STORAGE_DRIVER' => 'database', 'LANTERN_KEEP_RATE' => '1'];

        foreach ($lines as $key => $value) {
            if (preg_match('/^'.$key.'=/m', $env) === 1) {
                $env = (string) preg_replace('/^'.$key.'=.*$/m', $key.'='.$value, $env);
            } else {
                $env = rtrim($env, "\n").($env === '' ? '' : "\n").$key.'='.$value."\n";
            }
        }

        file_put_contents($path, $env);
        $this->line('Wrote LANTERN_ENABLED, LANTERN_STORAGE_DRIVER and LANTERN_KEEP_RATE to .env. Run php artisan migrate.');
    }
}
