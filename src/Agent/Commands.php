<?php

declare(strict_types=1);

namespace Lantern\Agent;

use Lantern\Console\CheckCommand;
use Lantern\Console\DiffCommand;
use Lantern\Console\IssueCommand;
use Lantern\Console\IssuesCommand;
use Lantern\Console\MatchCommand;
use Lantern\Console\ProbeCommand;
use Lantern\Console\RunCommand;
use Lantern\Console\SchemaDiffCommand;
use Lantern\Console\SchemaSnapshotCommand;
use Lantern\Console\TraceCommand;

final class Commands
{
    /** @return list<class-string> */
    public static function all(): array
    {
        return [IssuesCommand::class, IssueCommand::class, TraceCommand::class, DiffCommand::class, CheckCommand::class, MatchCommand::class, ProbeCommand::class, SchemaSnapshotCommand::class, SchemaDiffCommand::class, RunCommand::class];
    }
}
