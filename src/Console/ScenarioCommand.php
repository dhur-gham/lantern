<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Agent\AgentCommand;
use Lantern\Agent\AgentException;
use Lantern\Agent\Findings;
use Lantern\Agent\Reader;
use Lantern\Scenario\NoThrottle;
use Lantern\Scenario\Scenario;
use Lantern\Scenario\WriteGuard;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ScenarioCommand extends AgentCommand
{
    protected $signature = 'lantern:scenario
        {action : run or list}
        {file? : Scenario JSON file (a name from `list` also works)}
        {--repeat=1 : Run the scenario N times and report medians}
        {--commit : Keep the database writes of the scenario (default: roll them back)}
        {--diff= : Compare the first trace against this trace id or record name}
        {--format=json : json, ndjson, table or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'Run a Livewire / HTTP scenario file in this process, without a browser; list the scenario files';

    protected function perform(Reader $reader): int
    {
        return match ((string) $this->argument('action')) {
            'list' => $this->list(),
            'run' => $this->runScenario(),
            default => throw AgentException::usage('Unknown action: '.$this->argument('action'), 'Use: lantern:scenario run <file> or lantern:scenario list'),
        };
    }

    private function runScenario(): int
    {
        if (! $this->laravel->environment('local')) {
            throw AgentException::config('lantern:scenario only works with APP_ENV=local.', 'Scenarios run application code against the configured database; run them on a local copy.');
        }

        $file = $this->resolve((string) $this->argument('file'));
        $scenario = Scenario::fromFile($file);
        $fakes = $scenario->effectiveFakes(Scenario::fakes(config('lantern.scenarios.fake', [])));

        $script = $this->scriptPath();
        file_put_contents($script, <<<'PHP'
<?php
$scenario = \Lantern\Scenario\Scenario::fromFile($args['scenario_file']);
app(\Lantern\Scenario\ScenarioRunner::class)->run($scenario, empty($args['commit']));
PHP
        );

        try {
            $run = $this->laravel->make(RunCommand::class);
            $run->setLaravel($this->laravel);

            $input = new ArrayInput([
                'script' => $script,
                '--repeat' => (string) $this->option('repeat'),
                '--diff' => (string) $this->option('diff'),
                '--arg' => ['scenario_file='.$file, 'commit='.($this->option('commit') ? '1' : '')],
                '--format' => 'json',
            ] + ($this->option('commit') ? ['--commit' => true] : []));

            NoThrottle::reset();

            $buffer = new BufferedOutput;
            $code = $run->run($input, $buffer);
            $payload = json_decode($buffer->fetch(), true);

            if (! is_array($payload)) {
                throw AgentException::config('lantern:run gave no JSON result.');
            }
            if (isset($payload['error']) && ! isset($payload['schema'])) {
                $error = (array) $payload['error'];

                throw new AgentException((string) ($error['code'] ?? 'usage_error'), (string) ($error['message'] ?? 'Run failed.'), (int) ($error['exit_code'] ?? 2), $error['hint'] ?? null);
            }

            $payload = $this->withScenarioInfo($payload, $scenario, $file, $fakes);
            [$payload, $code] = $this->checkRollback($payload, $code);

            $this->emit($payload, 'traces');

            return $code;
        } finally {
            @unlink($script);
        }
    }

    /**
     * Reads the tables the run wrote to again, now that it is rolled back. A run that did not roll back cleanly fails.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function checkRollback(array $payload, int $code): array
    {
        if ($this->option('commit') || ! config('lantern.scenarios.verify_rollback', true) || ! $this->laravel->bound(WriteGuard::class)) {
            $payload['scenario']['rollback_check'] = null;

            return [$payload, $code];
        }

        $check = $this->laravel->make(WriteGuard::class)->verify();
        $payload['scenario']['rollback_check'] = $check;

        if ($check['unattributed'] !== []) {
            $payload['scenario']['warnings'][] = 'The rollback check does not cover '.count($check['unattributed']).' write statement(s) it could not attribute to a table: "'.implode('", "', $check['unattributed']).'".';
        }
        if ($check['ok']) {
            return [$payload, $code];
        }

        $payload['status'] = 'failed';
        $payload['scenario']['warnings'][] = 'The rollback check failed: '.implode(' ', $check['problems']);

        return [$payload, self::FAILURE];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fakes
     * @return array<string, mixed>
     */
    private function withScenarioInfo(array $payload, Scenario $scenario, string $file, array $fakes): array
    {
        $warnings = [];
        $unfaked = Scenario::unfakedEffects($fakes, $scenario->hasUploads());
        if ($scenario->hasWrites() && $unfaked !== []) {
            $warnings[] = 'Write actions ran without faking '.implode(', ', $unfaked).': whatever they trigger there (jobs queued, mail or notifications sent, calls to other services, files written to a storage disk) really happened, and the rollback does not undo it. Add them to "fake" in the scenario or to lantern.scenarios.fake.';
        }

        $throttle = $scenario->throttle ?? (bool) config('lantern.scenarios.throttle', true);
        $info = [
            'name' => $scenario->name,
            'file' => $this->relative($file),
            'steps' => count($scenario->steps),
            'faked' => $fakes,
            'throttle' => $throttle,
        ];
        if (! $throttle) {
            $info['throttle_skipped'] = NoThrottle::$skipped;
        }
        if ($warnings !== []) {
            $info['warnings'] = $warnings;
        }

        $keys = array_keys($payload);
        $at = (int) array_search('script', $keys, true);

        return array_slice($payload, 0, $at, true) + ['scenario' => $info] + array_slice($payload, $at, null, true);
    }

    /** A unique `.php` path in the temp folder; tempnam() creates the file, so the one it made is renamed, not left behind. */
    private function scriptPath(): string
    {
        $created = (string) tempnam(sys_get_temp_dir(), 'lantern-scenario-');
        $script = $created.'.php';
        rename($created, $script);

        return $script;
    }

    private function list(): int
    {
        $rows = [];
        foreach (glob($this->directory().DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            try {
                $scenario = Scenario::fromFile($path);
                $rows[] = ['name' => $scenario->name, 'file' => $this->relative($path), 'steps' => count($scenario->steps), 'valid' => true];
            } catch (AgentException $e) {
                $rows[] = ['name' => pathinfo($path, PATHINFO_FILENAME), 'file' => $this->relative($path), 'steps' => 0, 'valid' => false, 'error' => $e->getMessage()];
            }
        }

        $this->emit([
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.scenarios',
            'directory' => $this->relative($this->directory()),
            'scenarios' => $rows,
            'next' => $rows === []
                ? 'Create '.$this->relative($this->directory()).'/<name>.json, see docs/CLI.md "lantern:scenario".'
                : 'php artisan lantern:scenario run <name> --format=json',
        ], 'scenarios');

        return self::SUCCESS;
    }

    private function resolve(string $file): string
    {
        if ($file === '') {
            throw AgentException::usage('Missing scenario file.', 'Run: php artisan lantern:scenario run lantern/scenarios/<name>.json');
        }

        foreach ([$file, base_path($file), $this->directory().DIRECTORY_SEPARATOR.$file, $this->directory().DIRECTORY_SEPARATOR.$file.'.json'] as $candidate) {
            if (is_file($candidate)) {
                return (string) realpath($candidate);
            }
        }

        throw AgentException::notFound('Scenario not found: '.$file, 'Run: php artisan lantern:scenario list');
    }

    private function directory(): string
    {
        $path = config('lantern.scenarios.path');

        return is_string($path) && $path !== '' ? $path : base_path('lantern/scenarios');
    }

    private function relative(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
