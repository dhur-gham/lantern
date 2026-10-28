<?php

declare(strict_types=1);

namespace Lantern\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('lantern_diff')]
#[Description('Compare two traces: resolved and new issues, query count and time deltas, with a verdict (improved, regressed, same). Use to verify a fix.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class LanternDiff extends LanternTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'before' => $schema->string()->description('Trace id before the change, or "last"')->required(),
            'after' => $schema->string()->description('Trace id after the change, or "last"')->required(),
        ];
    }

    protected function command(): string
    {
        return 'lantern:diff';
    }

    protected function rules(): array
    {
        return ['before' => 'required|string', 'after' => 'required|string'];
    }

    protected function parameters(array $input): array
    {
        return ['before' => $input['before'], 'after' => $input['after']];
    }
}
