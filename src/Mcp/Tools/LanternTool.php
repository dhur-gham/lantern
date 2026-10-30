<?php

declare(strict_types=1);

namespace Lantern\Mcp\Tools;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Lantern\Agent\Commands;

/**
 * Thin wrapper over the lantern:* CLI commands so MCP and CLI share one code path and one JSON schema.
 */
abstract class LanternTool extends Tool
{
    abstract protected function command(): string;

    /** @return array<string, mixed> */
    abstract protected function rules(): array;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    abstract protected function parameters(array $input): array;

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $request->validate($this->rules());
        $this->ensureCommands();

        Artisan::call($this->command(), $this->parameters($input) + ['--format' => 'json']);
        $out = trim(Artisan::output());
        $payload = json_decode($out, true);

        if (! is_array($payload) || $payload === []) {
            return Response::error($out !== '' ? $out : 'Lantern returned no output.');
        }
        if (isset($payload['error'])) {
            return Response::error((string) json_encode(self::asToolHints($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return Response::structured(self::asToolHints($payload));
    }

    /**
     * Hints and `next` are written for the CLI; point them at the matching MCP tool instead.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function asToolHints(array $payload): array
    {
        $tools = ['issues' => 'lantern_issues', 'issue' => 'lantern_issue', 'trace' => 'lantern_trace_summary', 'diff' => 'lantern_diff', 'check' => 'lantern_check', 'match' => 'lantern_match'];
        $rewrite = fn (string $text): string => (string) preg_replace_callback(
            '/php artisan lantern:(issues|issue|trace|diff|check|match)((?: [^\s.,]+)*)(?=$|\.(?:\s|$)|,)/',
            function (array $m) use ($tools): string {
                $args = array_values(array_filter(preg_split('/\s+/', trim($m[2])) ?: [], fn (string $a): bool => $a !== '' && ! str_starts_with($a, '--')));
                $with = match ($m[1]) {
                    'issue' => isset($args[0]) ? ' with id='.$args[0] : '',
                    'diff' => isset($args[1]) ? ' with before='.$args[0].', after='.$args[1] : '',
                    default => '',
                };

                return 'the '.$tools[$m[1]].' tool'.$with;
            },
            $text,
        );
        foreach (['next', 'hint'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $payload[$key] = $rewrite($payload[$key]);
            }
        }
        if (isset($payload['error']['hint']) && is_string($payload['error']['hint'])) {
            $payload['error']['hint'] = $rewrite($payload['error']['hint']);
        }

        return $payload;
    }

    private function ensureCommands(): void
    {
        if (array_key_exists($this->command(), Artisan::all())) {
            return;
        }
        $kernel = app(Kernel::class);
        foreach (Commands::all() as $class) {
            $kernel->registerCommand(app($class));
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $map input key => cli option
     * @return array<string, mixed>
     */
    protected function options(array $input, array $map): array
    {
        $out = [];
        foreach ($map as $key => $option) {
            if (! isset($input[$key]) || $input[$key] === '' || $input[$key] === false) {
                continue;
            }
            $out['--'.$option] = $input[$key];
        }

        return $out;
    }
}
