<?php

declare(strict_types=1);

namespace Lantern\Agent;

use Lantern\Console\CheckCommand;
use Lantern\Console\DiffCommand;
use Lantern\Console\IssueCommand;
use Lantern\Console\IssuesCommand;
use Lantern\Console\TraceCommand;

final class Commands
{
    /** @return list<class-string> */
    public static function all(): array
    {
        return [IssuesCommand::class, IssueCommand::class, TraceCommand::class, DiffCommand::class, CheckCommand::class];
    }
}
