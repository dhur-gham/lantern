<?php

declare(strict_types=1);

namespace Lantern\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('lantern_match')]
#[Description('Find the code behind a SQL statement seen in production (an APM span description or log line): call sites with file:line, the issues recorded on that query and its plan. Returns lantern.match JSON. Placeholders %s, ?, $1 and :name all work.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class LanternMatch extends LanternTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'sql' => $schema->string()->description('The SQL statement, as seen in production')->required(),
            'traces' => $schema->integer()->description('Recent traces searched, 1-500 (default 200)')->min(1)->max(500),
            'since' => $schema->string()->description('Only traces started within a window (30m, 24h, 7d) or after a date'),
            'path' => $schema->string()->description('Only traces whose path matches (pattern, e.g. api/otp/*)'),
            'route' => $schema->string()->description('Only traces whose route name matches (pattern)'),
            'status' => $schema->string()->description('Only traces with this status (404 or 5xx)'),
            'app' => $schema->string()->description('Only traces of this app (default: the current app)'),
            'all_apps' => $schema->boolean()->description('Search the traces of every app'),
            'include_resolved' => $schema->boolean()->description('Also report issues that later runs showed are gone'),
            'limit' => $schema->integer()->description('Max matches returned, 1-50 (default 5)')->min(1)->max(50),
        ];
    }

    protected function command(): string
    {
        return 'lantern:match';
    }

    protected function rules(): array
    {
        return [
            'sql' => 'required|string',
            'traces' => 'nullable|integer|min:1|max:500',
            'since' => 'nullable|string',
            'path' => 'nullable|string',
            'route' => 'nullable|string',
            'status' => 'nullable|string',
            'app' => 'nullable|string',
            'all_apps' => 'nullable|boolean',
            'include_resolved' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:50',
        ];
    }

    protected function parameters(array $input): array
    {
        return $this->options($input, ['sql' => 'sql', 'traces' => 'traces', 'since' => 'since', 'path' => 'path', 'route' => 'route', 'status' => 'status', 'app' => 'app', 'all_apps' => 'all-apps', 'include_resolved' => 'include-resolved', 'limit' => 'limit']);
    }
}
