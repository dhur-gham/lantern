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
            'limit' => $schema->integer()->description('Max query groups, 1-50 (default 10)')->min(1)->max(50),
        ];
    }

    protected function command(): string
    {
        return 'lantern:trace';
    }

    protected function rules(): array
    {
        return ['trace_id' => 'nullable|string', 'slow' => 'nullable|boolean', 'limit' => 'nullable|integer|min:1|max:50'];
    }

    protected function parameters(array $input): array
    {
        $params = ['--summary' => true] + $this->options($input, ['slow' => 'slow', 'limit' => 'limit']);
        if (! empty($input['trace_id'])) {
            $params['id'] = $input['trace_id'];
        }

        return $params;
    }
}
