<?php

declare(strict_types=1);

namespace Lantern\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('lantern_trace_summary')]
#[Description('Summarise a stored request trace: totals, issues and the slowest query groups. Defaults to the latest trace.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class LanternTraceSummary extends LanternTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'trace_id' => $schema->string()->description('Trace id; omit for the latest trace'),
            'slow' => $schema->boolean()->description('Pick the slowest of the recent traces instead of the latest'),
            'path' => $schema->string()->description('Latest trace whose path matches (pattern, e.g. api/otp/*)'),
            'route' => $schema->string()->description('Latest trace whose route name matches (pattern)'),
            'status' => $schema->string()->description('Latest trace with this status (404 or 5xx)'),
            'since' => $schema->string()->description('Only traces from a window (30m, 24h, 7d) or after a date'),
            'app' => $schema->string()->description('Only traces of this app (default: the current app)'),
            'all_apps' => $schema->boolean()->description('Include traces of every app'),
            'limit' => $schema->integer()->description('Max query groups, 1-50 (default 10)')->min(1)->max(50),
        ];
    }

    protected function command(): string
    {
        return 'lantern:trace';
    }

    protected function rules(): array
    {
        return [
            'trace_id' => 'nullable|string', 'slow' => 'nullable|boolean', 'path' => 'nullable|string', 'route' => 'nullable|string',
            'status' => 'nullable|string', 'since' => 'nullable|string', 'app' => 'nullable|string', 'all_apps' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:50',
        ];
    }

    protected function parameters(array $input): array
    {
        $params = ['--summary' => true] + $this->options($input, [
            'slow' => 'slow', 'path' => 'path', 'route' => 'route', 'status' => 'status', 'since' => 'since',
            'app' => 'app', 'all_apps' => 'all-apps', 'limit' => 'limit',
        ]);
        if (! empty($input['trace_id'])) {
            $params['id'] = $input['trace_id'];
        }

        return $params;
    }
}
