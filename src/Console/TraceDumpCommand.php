<?php

namespace Lantern\Console;

use Illuminate\Console\Command;
use Lantern\Instrumentation\Lifecycle\MemorySink;
use Lantern\Instrumentation\Lifecycle\TraceFormatter;
use Lantern\Instrumentation\Lifecycle\TraceSink;

class TraceDumpCommand extends Command
{
    protected $signature = 'lantern:trace:dump {--last : Print the latest trace (default)}';

    protected $description = 'Print the latest trace as an indented tree';

    public function handle(): int
    {
        // In-process trace first (only present when a request ran in this process).
        if ($this->laravel->bound(TraceSink::class)) {
            $sink = $this->laravel->make(TraceSink::class);
            if ($sink instanceof MemorySink && $sink->last() !== null) {
                $this->line(TraceFormatter::tree($sink->last()));

                return self::SUCCESS;
            }
        }

        $file = storage_path('lantern/last-trace.json');
        if (! is_file($file)) {
            $this->warn('No trace found. Set LANTERN_ENABLED=true and LANTERN_DEBUG_WRITE_LAST_TRACE=true, then make a request.');

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            $this->error('Could not read '.$file);

            return self::FAILURE;
        }

        $this->line(TraceFormatter::fromArray($data));

        return self::SUCCESS;
    }
}
