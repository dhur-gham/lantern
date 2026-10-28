<?php

declare(strict_types=1);

namespace Lantern\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('lantern_issue')]
#[Description('Show one issue with full redacted evidence and its location. Returns lantern.issue JSON. Bindings are always masked.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class LanternIssue extends LanternTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Issue id from lantern_issues (a unique prefix of at least 6 characters works)')->required(),
        ];
    }

    protected function command(): string
    {
        return 'lantern:issue';
    }

    protected function rules(): array
    {
        return ['id' => 'required|string'];
    }

    protected function parameters(array $input): array
    {
        return ['key' => $input['id']];
    }
}
