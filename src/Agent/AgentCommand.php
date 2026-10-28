<?php

declare(strict_types=1);

namespace Lantern\Agent;

use Illuminate\Console\Command;
use Lantern\Storage\TraceQueries;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

abstract class AgentCommand extends Command
{
    /** @var list<string> */
    protected array $itemFields = [];

    abstract protected function perform(Reader $reader): int;

    /** Unknown options and missing arguments exit 2 (usage), never 1, which is reserved for a failed lantern:check. */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface $e) {
            $error = AgentException::usage($e->getMessage(), 'Run: php artisan '.$this->getName().' --help');
            $format = strtolower((string) $input->getParameterOption('--format', 'table'));
            $text = in_array($format, ['table', 'markdown'], true) || ! in_array($format, Output::FORMATS, true)
                ? $error->getMessage().PHP_EOL.$error->hint
                : (string) json_encode($error->toPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $output->writeln($text, OutputInterface::OUTPUT_RAW);

            return $error->exitCode;
        }
    }

    public function handle(): int
    {
        try {
            $format = $this->format();
            if (config('lantern.storage.driver', 'memory') !== 'database') {
                throw AgentException::config(
                    'Lantern storage is not configured. Set LANTERN_STORAGE_DRIVER=database and run the migrations.',
                    'Set LANTERN_STORAGE_DRIVER=database, run php artisan migrate, then retry.',
                );
            }

            return $this->perform(new Reader($this->laravel->make(TraceQueries::class)));
        } catch (AgentException $e) {
            return $this->failWith($e);
        } catch (Throwable $e) {
            return $this->failWith($this->storageError($e));
        }
    }

    private function storageError(Throwable $e): AgentException
    {
        $message = trim((string) preg_replace('/\s*\(Connection:.*$/s', '', $e->getMessage()));
        $hint = match (true) {
            (bool) preg_match('/base table or view not found|no such table|undefined table|doesn.t exist/i', $message) => 'Lantern tables are missing. Run php artisan migrate; if it says nothing to migrate, delete the lantern_* rows from the migrations table and migrate again.',
            (bool) preg_match('/connection refused|could not find driver|access denied|unknown database|getaddrinfo/i', $message) => 'Check the DB_* settings (or LANTERN_DB_CONNECTION) and that the database is running.',
            default => null,
        };

        return new AgentException(AgentException::STORAGE, 'Could not read Lantern storage: '.$message, 2, $hint);
    }

    protected function format(): string
    {
        $format = strtolower((string) ($this->option('format') ?: 'table'));
        if (! in_array($format, Output::FORMATS, true)) {
            throw AgentException::usage('Invalid --format: '.$format, 'Use one of: '.implode(', ', Output::FORMATS).'.');
        }

        return $format;
    }

    protected function intOption(string $name, int $default, int $min = 0, int $max = Reader::MAX_ROWS): int
    {
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (! is_numeric($raw) || (int) $raw != $raw || (int) $raw < $min || (int) $raw > $max) {
            throw AgentException::usage("--$name must be an integer between $min and $max.", null);
        }

        return (int) $raw;
    }

    protected function floatOption(string $name): ?float
    {
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw < 0) {
            throw AgentException::usage("--$name must be a non-negative number.");
        }

        return (float) $raw;
    }

    /** @return list<string>|null */
    protected function fields(): ?array
    {
        $raw = $this->option('fields');
        if ($raw === null || $raw === '') {
            return null;
        }
        $fields = array_values(array_filter(array_map('trim', explode(',', (string) $raw)), fn ($f) => $f !== ''));
        $bad = array_diff($fields, $this->itemFields);
        if ($this->itemFields !== [] && $bad !== []) {
            throw AgentException::usage('Unknown --fields: '.implode(', ', $bad), 'Available: '.implode(', ', $this->itemFields).'.');
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null $columns
     */
    protected function emit(array $payload, ?string $items = null, ?array $columns = null): void
    {
        $text = Output::render($this->format(), $payload, $items, $columns, (bool) $this->option('pretty'));
        $this->output->writeln($this->format() === 'table' ? explode("\n", $text) : $text, OutputInterface::OUTPUT_RAW);
    }

    protected function failWith(AgentException $e): int
    {
        $format = 'table';
        try {
            $format = $this->format();
        } catch (AgentException) {
        }
        $text = $format === 'table' || $format === 'markdown'
            ? $e->getMessage().($e->hint !== null ? "\n".$e->hint : '')
            : (string) json_encode($e->toPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->output->writeln($text, OutputInterface::OUTPUT_RAW);

        return $e->exitCode;
    }

    protected function truncationHint(int $returned, int $total, int $offset, string $flag = '--limit'): string
    {
        return sprintf('%d of %d shown; use --offset=%d to continue or raise %s.', $returned, $total, $offset + $returned, $flag);
    }
}
