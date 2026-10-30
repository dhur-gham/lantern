<?php

declare(strict_types=1);

namespace Lantern\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('lantern_issues')]
#[Description('List detected performance issues (N+1, slow and duplicate queries) with file:line. Start here. Returns lantern.issues JSON, paged with limit/offset.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class LanternIssues extends LanternTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('Filter by issue type, e.g. n_plus_one'),
            'severity' => $schema->string()->description('Filter by severity')->enum(['critical', 'warning', 'info']),
            'sort' => $schema->string()->description('Sort order (default total_ms)')->enum(['total_ms', 'occurrences', 'last_seen']),
            'since' => $schema->string()->description('Only issues last seen within a window (30m, 24h, 7d) or after a date'),
            'path' => $schema->string()->description('Only issues whose latest trace path matches (pattern, e.g. api/otp/*)'),
            'route' => $schema->string()->description('Only issues whose latest trace route name matches (pattern)'),
            'status' => $schema->string()->description('Only issues whose latest trace has this status (404 or 5xx)'),
            'app' => $schema->string()->description('Only issues of this app (default: the current app)'),
            'all_apps' => $schema->boolean()->description('Include issues of every app'),
            'include_resolved' => $schema->boolean()->description('Also list resolved issues (hidden by default: the query ran again without the finding)'),
            'resolved' => $schema->boolean()->description('Only list resolved issues'),
            'limit' => $schema->integer()->description('Max issues returned, 1-50 (default 10)')->min(1)->max(50),
            'offset' => $schema->integer()->description('Skip this many issues (use next_offset from a truncated result)')->min(0),
            'full' => $schema->boolean()->description('Return every field including evidence'),
        ];
    }

    protected function command(): string
    {
        return 'lantern:issues';
    }

    protected function rules(): array
    {
        return [
            'type' => 'nullable|string',
            'severity' => 'nullable|in:critical,warning,info',
            'sort' => 'nullable|in:total_ms,occurrences,last_seen',
            'since' => 'nullable|string',
            'path' => 'nullable|string',
            'route' => 'nullable|string',
            'status' => 'nullable|string',
            'app' => 'nullable|string',
            'all_apps' => 'nullable|boolean',
            'include_resolved' => 'nullable|boolean',
            'resolved' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:50',
            'offset' => 'nullable|integer|min:0',
            'full' => 'nullable|boolean',
        ];
    }

    protected function parameters(array $input): array
    {
        return $this->options($input, ['type' => 'type', 'severity' => 'severity', 'sort' => 'sort', 'since' => 'since', 'path' => 'path', 'route' => 'route', 'status' => 'status', 'app' => 'app', 'all_apps' => 'all-apps', 'include_resolved' => 'include-resolved', 'resolved' => 'resolved', 'limit' => 'limit', 'offset' => 'offset', 'full' => 'full']);
    }
}
