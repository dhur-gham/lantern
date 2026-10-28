<?php

declare(strict_types=1);

namespace Lantern\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Lantern\Mcp\Tools\LanternCheck;
use Lantern\Mcp\Tools\LanternDiff;
use Lantern\Mcp\Tools\LanternIssue;
use Lantern\Mcp\Tools\LanternIssues;
use Lantern\Mcp\Tools\LanternTraceSummary;

#[Name('lantern')]
#[Version('1.0.0')]
#[Instructions('Lantern: Laravel request traces and performance issues with file:line. Start with lantern_issues; fix; verify with lantern_check. All tools are read-only.')]
final class LanternServer extends Server
{
    protected array $tools = [
        LanternIssues::class,
        LanternIssue::class,
        LanternTraceSummary::class,
        LanternDiff::class,
        LanternCheck::class,
    ];
}
