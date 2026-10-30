<?php

declare(strict_types=1);

namespace Lantern\Console;

use Lantern\Agent\AgentCommand;
use Lantern\Agent\AgentException;
use Lantern\Agent\Findings;
use Lantern\Agent\Reader;
use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Lifecycle\PersistSink;
use Lantern\Instrumentation\Lifecycle\RecordOnlySink;
use Lantern\Instrumentation\Lifecycle\TraceSink;
use Lantern\LanternServiceProvider;
use Lantern\Recording;
use Lantern\Sampling\Sampler;
use Lantern\Storage\TraceStore;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class RunCommand extends AgentCommand
{
    protected $signature = 'lantern:run
        {script : PHP file to run with the app booted; Lantern::record() blocks inside become traces}
        {--repeat=1 : Run the script N times and report medians}
        {--commit : Keep the database writes of the script (default: roll them back)}
        {--diff= : Compare the first trace against this trace id or record name}
        {--arg=* : key=value passed to the script as $args (any other --key=value works too)}
        {--format=json : json, ndjson, table or markdown}
        {--pretty : Pretty-print json}';

    protected $description = 'Run a PHP script with Lantern on; each Lantern::record() block becomes a trace';

    /** @var list<string>|null */
    private ?array $raw = null;

    protected function configure(): void
    {
        parent::configure();
        // options meant for the script are not declared, so they must not fail the parse
        $this->ignoreValidationErrors();
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        $this->raw = null;
        if ($input instanceof ArgvInput) {
            $argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));
            $at = array_search('lantern:run', $argv, true);
            $this->raw = $at === false ? null : array_slice($argv, $at + 1);
        }

        return parent::run($input, $output);
    }

    protected function perform(Reader $reader): int
    {
        if (! $this->laravel->environment('local')) {
            throw AgentException::config('lantern:run only works with APP_ENV=local.', 'Probes run application code against the configured database; run them on a local copy.');
        }

        $opts = $this->parsed();
        $script = $this->resolveScript($opts['script']);
        $diffBase = $opts['diff'] !== null ? $this->resolveDiff($reader, $opts['diff']) : null;

        $provider = $this->laravel->getProvider(LanternServiceProvider::class);
        if ($provider instanceof LanternServiceProvider) {
            $provider->enableForProcess();
        }

        // write traces once the transaction of the script is gone: on the default connection they would be rolled back with it
        $sink = $this->deferredSink();
        $recording = $this->laravel->make(Recording::class);
        $recording->forget();

        $rollback = $opts['commit'] ? null : new ConnectionRollback(
            $this->laravel['db'],
            $this->laravel['events'],
            $this->storageConnection(),
        );

        $error = null;
        $runs = [];
        $connections = [];
        for ($i = 0; $i < $opts['repeat']; $i++) {
            $before = count($recording->finished());
            $error = $this->runScript($script, $opts['args'], $recording, $rollback, $connections);
            $runs[] = array_slice($recording->finished(), $before);
            if ($error !== null) {
                break;
            }
        }
        $sink?->flush();

        $traces = array_merge([], ...$runs);
        $failed = $error !== null || array_filter($traces, fn (array $t): bool => $t['status'] === 'failed') !== [];

        $payload = [
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.run',
            'script' => $opts['script'],
            'args' => $opts['args'],
            'repeat' => $opts['repeat'],
            'rolled_back' => ! $opts['commit'] && ! in_array(false, $connections, true),
            'connections' => $connections,
            'status' => $failed ? 'failed' : 'ok',
            'traces' => $traces,
        ];
        if ($error !== null) {
            $payload['error'] = $error;
        }
        if ($opts['repeat'] > 1) {
            $payload['summary'] = $this->summary($runs);
        }
        if ($diffBase !== null && $traces !== []) {
            $payload['diff'] = $this->diff($diffBase, (string) $traces[0]['trace_id']);
        }
        $payload['next'] = $traces === []
            ? 'No Lantern::record() block ran. Wrap the code to measure in Lantern::record(\'name\', fn () => ...).'
            : 'php artisan lantern:issues --route=\'record:*\' --format=json';
        if ($error !== null) {
            $payload['hint'] = 'The script threw; the traces above are those recorded before it did.';
        }

        $this->emit($payload, 'traces');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, bool> $connections rolled back or not, per connection the script touched; updated in place
     * @return array{class: string, message: string}|null
     */
    private function runScript(string $script, array $args, Recording $recording, ?ConnectionRollback $rollback, array &$connections): ?array
    {
        $rollback?->start();

        try {
            (static function (string $__script, array $args, Recording $lantern): void {
                require $__script;
            })($script, $args, $recording);

            return null;
        } catch (Throwable $e) {
            return ['class' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 500)];
        } finally {
            if ($rollback !== null) {
                foreach ($rollback->rollBack() as $name => $done) {
                    $connections[$name] = ($connections[$name] ?? true) && $done;
                }
            }
        }
    }

    /** @return list<string> Lantern's own storage connection, unless it is the default one */
    private function storageConnection(): array
    {
        $name = config('lantern.storage.connection');

        return is_string($name) && $name !== '' && $name !== config('database.default') ? [$name] : [];
    }

    private function deferredSink(): ?PersistSink
    {
        if (! $this->laravel->bound(TraceStore::class)) {
            return null;
        }

        $sink = new PersistSink(
            $this->laravel->make(TraceStore::class),
            $this->laravel->make(Sampler::class),
            $this->laravel->make(MemorySink::class),
            null,
            PHP_INT_MAX,
        );
        $this->laravel->forgetInstance(TraceSink::class);
        $this->laravel->instance(TraceSink::class, new RecordOnlySink($sink));

        return $sink;
    }

    /** @return array{script: string, repeat: int, commit: bool, diff: ?string, args: array<string, mixed>} */
    private function parsed(): array
    {
        if ($this->raw === null) {
            $args = [];
            foreach ((array) $this->option('arg') as $pair) {
                [$k, $v] = array_pad(explode('=', (string) $pair, 2), 2, true);
                $args[$k] = $v;
            }

            return [
                'script' => (string) $this->argument('script'),
                'repeat' => $this->repeat((string) $this->option('repeat')),
                'commit' => (bool) $this->option('commit'),
                'diff' => (string) $this->option('diff') !== '' ? (string) $this->option('diff') : null,
                'args' => $args,
            ];
        }

        $script = null;
        $repeat = '1';
        $commit = false;
        $diff = null;
        $args = [];
        foreach ($this->raw as $t) {
            if (! str_starts_with($t, '-')) {
                $script ??= $t;

                continue;
            }
            [$key, $value] = array_pad(explode('=', ltrim($t, '-'), 2), 2, null);
            switch ($key) {
                case 'repeat':
                    $repeat = (string) $value;
                    break;
                case 'commit':
                    $commit = true;
                    break;
                case 'diff':
                    $diff = $value === null ? null : (string) $value;
                    break;
                case 'pretty':
                    $this->input->setOption('pretty', true);
                    break;
                case 'format':
                    $this->input->setOption('format', (string) $value);
                    break;
                case 'arg':
                    [$k, $v] = array_pad(explode('=', (string) $value, 2), 2, true);
                    $args[$k] = $v;
                    break;
                case 'v': case 'vv': case 'vvv': case 'q': case 'n': case 'h': case 'V':
                case 'verbose': case 'quiet': case 'no-interaction': case 'ansi': case 'no-ansi': case 'help': case 'version': case 'env':
                    break;
                default:
                    $args[$key] = $value ?? true;
            }
        }

        if ($script === null) {
            throw AgentException::usage('Missing script.', 'Run: php artisan lantern:run path/to/probe.php');
        }

        return ['script' => $script, 'repeat' => $this->repeat($repeat), 'commit' => $commit, 'diff' => $diff, 'args' => $args];
    }

    private function repeat(string $raw): int
    {
        if (! ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 1000) {
            throw AgentException::usage('--repeat must be an integer between 1 and 1000.');
        }

        return (int) $raw;
    }

    private function resolveScript(string $script): string
    {
        foreach ([$script, base_path($script), getcwd().DIRECTORY_SEPARATOR.$script] as $candidate) {
            if (is_file($candidate)) {
                return (string) realpath($candidate);
            }
        }

        throw AgentException::notFound('Script not found: '.$script, 'Pass a path relative to the project root or the current directory.');
    }

    private function resolveDiff(Reader $reader, string $ref): string
    {
        if ($reader->traceRow($ref) !== null) {
            return $ref;
        }
        $rows = $reader->traces(['route_is' => 'record:'.$ref], 1);
        if ($rows === []) {
            throw AgentException::noData('--diff: no stored trace or record named "'.$ref.'".', 'Run the script once before the change, then pass the same --diff name after it.');
        }

        return (string) $rows[0]['id'];
    }

    /** @return array<string, mixed>|null */
    private function diff(string $before, string $after): ?array
    {
        try {
            // run the command object itself: a nested Artisan::call would replace the output of this one
            $command = $this->laravel->make(DiffCommand::class);
            $command->setLaravel($this->laravel);
            $buffer = new BufferedOutput;
            $command->run(new ArrayInput(['before' => $before, 'after' => $after, '--format' => 'json']), $buffer);
            $decoded = json_decode($buffer->fetch(), true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<list<array<string, mixed>>> $runs
     * @return list<array<string, mixed>>
     */
    private function summary(array $runs): array
    {
        $byName = [];
        foreach ($runs as $traces) {
            foreach ($traces as $t) {
                $byName[$t['name']]['ms'][] = (float) $t['duration_ms'];
                $byName[$t['name']]['queries'][] = (int) $t['query_count'];
            }
        }

        $median = function (array $values): float {
            sort($values);
            $n = count($values);

            return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
        };

        $out = [];
        foreach ($byName as $name => $m) {
            $out[] = ['name' => $name, 'runs' => count($m['ms']), 'median_ms' => round($median($m['ms']), 2), 'min_ms' => min($m['ms']), 'max_ms' => max($m['ms']), 'median_queries' => $median($m['queries'])];
        }

        return $out;
    }
}
