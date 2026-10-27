<?php

declare(strict_types=1);

namespace Lantern\Console;

use Illuminate\Console\Command;
use Lantern\Storage\Pruner;

final class PruneCommand extends Command
{
    protected $signature = 'lantern:prune {--days= : Trace retention in days} {--issues-days= : Issue retention in days} {--dry-run : Count without deleting}';

    protected $description = 'Delete stored Lantern traces and issues past their retention window';

    public function handle(): int
    {
        $days = $this->option('days');
        $issueDays = $this->option('issues-days');
        $dry = (bool) $this->option('dry-run');

        $result = (new Pruner(10_000))->prune(
            $days === null ? null : (int) $days,
            $issueDays === null ? null : (int) $issueDays,
            $dry,
        );

        $verb = $dry ? 'Would delete' : 'Deleted';
        $this->line("{$verb} {$result['traces']} traces and {$result['issues']} issues.");

        return self::SUCCESS;
    }
}
