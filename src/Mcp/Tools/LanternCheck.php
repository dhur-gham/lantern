<?php

declare(strict_types=1);

namespace Lantern\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('lantern_check')]
#[Description('Verification gate: pass/fail against query-count, time and issue thresholds. pass=false is a normal result (exit_code 1), not a tool error.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class LanternCheck extends LanternTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'route' => $schema->string()->description('Only check traces for this route name or path'),
            'path' => $schema->string()->description('Only check traces whose path matches (pattern)'),
            'status' => $schema->string()->description('Only check traces with this status (404 or 5xx)'),
            'app' => $schema->string()->description('Only check this app (default: the current app)'),
            'all_apps' => $schema->boolean()->description('Check every app'),
            'max_queries' => $schema->integer()->description('Fail when a trace runs more queries than this')->min(0),
            'max_ms' => $schema->number()->description('Fail when a trace takes longer than this many ms')->min(0),
            'fail_on' => $schema->string()->description('Lowest issue severity that fails (default critical)')->enum(['critical', 'warning', 'info']),
            'no_new_issues' => $schema->boolean()->description('Fail on any issue missing from the baseline (or any issue without a baseline)'),
            'baseline' => $schema->string()->description('Path to a JSON file of accepted issue ids'),
            'since' => $schema->string()->description('Only check traces and issues from this window (30m, 24h, 7d) or date'),
            'limit' => $schema->integer()->description('Max violations listed, 1-100 (default 20)')->min(1)->max(100),
        ];
    }

    protected function command(): string
    {
        return 'lantern:check';
    }

    protected function rules(): array
    {
        return [
            'route' => 'nullable|string',
            'path' => 'nullable|string',
            'status' => 'nullable|string',
            'app' => 'nullable|string',
            'all_apps' => 'nullable|boolean',
            'max_queries' => 'nullable|integer|min:0',
            'max_ms' => 'nullable|numeric|min:0',
            'fail_on' => 'nullable|in:critical,warning,info',
            'no_new_issues' => 'nullable|boolean',
            'baseline' => 'nullable|string',
            'since' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    protected function parameters(array $input): array
    {
        return $this->options($input, [
            'route' => 'route', 'path' => 'path', 'status' => 'status', 'app' => 'app', 'all_apps' => 'all-apps', 'max_queries' => 'max-queries', 'max_ms' => 'max-ms', 'fail_on' => 'fail-on',
            'no_new_issues' => 'no-new-issues', 'baseline' => 'baseline', 'since' => 'since', 'limit' => 'limit',
        ]);
    }
}
