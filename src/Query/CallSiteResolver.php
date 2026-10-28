<?php

declare(strict_types=1);

namespace Lantern\Query;

use Lantern\Instrumentation\Lifecycle\ViewNames;

final class CallSiteResolver
{
    private const PAGING = [
        'chunk' => 1, 'chunkById' => 1, 'chunkByIdDesc' => 1, 'orderedChunkById' => 1,
        'lazy' => 1, 'lazyById' => 1, 'lazyByIdDesc' => 1, 'orderedLazyById' => 1,
        'each' => 1, 'eachById' => 1,
    ];

    /** @var list<string> */
    private array $roots;

    private string $base;

    private string $own;

    /** @var array<string, array{0: string, 1: bool, 2: bool, 3: bool}> path, is own code, is app code, is under vendor/, by raw file name */
    private array $files = [];

    /** @param list<string> $appPaths */
    public function __construct(array $appPaths, string $basePath, private int $limit = 50)
    {
        $this->roots = [];
        foreach ($appPaths as $path) {
            $real = @realpath($path);
            foreach (array_unique([self::normalize($path), $real === false ? '' : self::normalize($real)]) as $root) {
                if ($root !== '') {
                    $this->roots[] = $root;
                }
            }
        }

        $realBase = @realpath($basePath);
        $this->base = self::normalize($realBase === false ? $basePath : $realBase);
        $this->own = self::normalize(dirname(__DIR__));
    }

    /** @return array{file: string, line: int, function: ?string, class: ?string, view: ?string, vendor: bool, depth: int, paging?: true}|null */
    public function resolve(): ?array
    {
        return $this->resolveFromTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->limit));
    }

    /**
     * 'function'/'class' describe the code enclosing the call site (the next frame up the stack).
     *
     * @param  list<array<string, mixed>>  $trace
     * @return array{file: string, line: int, function: ?string, class: ?string, view: ?string, vendor: bool, depth: int, paging?: true}|null
     */
    public function resolveFromTrace(array $trace): ?array
    {
        $trace = array_values($trace);
        $fallback = null;
        $paging = false;

        foreach ($trace as $index => $frame) {
            if (! $paging && self::isPagingFrame($frame)) {
                $paging = true;
            }

            $file = $frame['file'] ?? null;
            if (! is_string($file) || $file === '') {
                continue;
            }

            if (! isset($this->files[$file])) {
                if (count($this->files) > 2000) {
                    $this->files = [];
                }
                $p = self::normalize($file);
                $this->files[$file] = [$p, $this->isOwn($p), $this->isApp($p), self::inVendor($p)];
            }
            [$path, $own, $appFile, $vendorFile] = $this->files[$file];
            if ($own) {
                continue;
            }

            $line = (int) ($frame['line'] ?? 0);
            $view = null;

            if (self::isCompiledView($path)) {
                $source = ViewNames::sourceFromCompiled($file);
                if ($source === null) {
                    continue;
                }
                $view = ViewNames::fromPath($source);
                $sourcePath = self::normalize($source);
                if (is_file($source) && $line > 0 && $line <= $this->lineCount($source)) {
                    $path = $sourcePath;
                }
                $isApp = $this->isApp($sourcePath);
                $isVendor = ! $isApp && self::inVendor($sourcePath);
            } else {
                $isApp = $appFile;
                if (! $isApp && ($fallback !== null || ! $vendorFile)) {
                    continue; // a vendor fallback is already held, or this is neither app nor vendor code
                }
                $isVendor = ! $isApp && ! self::isFrameworkInternal($path, $frame);
            }

            if (! $isApp && ! ($isVendor && $fallback === null)) {
                continue;
            }

            $next = $trace[$index + 1] ?? [];
            $hit = [
                'file' => $this->relative($path),
                'line' => $line,
                'function' => isset($next['function']) ? (string) $next['function'] : null,
                'class' => isset($next['class']) ? (string) $next['class'] : null,
                'view' => $view,
                'vendor' => ! $isApp,
                'depth' => (int) $index,
            ];
            if ($paging) {
                $hit['paging'] = true;
            }

            if ($isApp) {
                return $hit;
            }
            $fallback = $hit;
        }

        return $fallback;
    }

    /** @param array<string, mixed> $frame */
    private static function isPagingFrame(array $frame): bool
    {
        $class = $frame['class'] ?? null;
        if (! is_string($class) || ! str_starts_with($class, 'Illuminate'.chr(92).'Database'.chr(92))) {
            return false;
        }

        $function = (string) ($frame['function'] ?? '');
        if (isset(self::PAGING[$function])) {
            return true;
        }

        // PHP < 8.4 names closures `Namespace\{closure}` with no method; these are the paging closures (chunkMap, each, lazy, ...) and carry nothing else
        if ($function === 'Illuminate\Database\Concerns\{closure}') {
            return true;
        }

        return str_starts_with($function, '{closure:') && preg_match('/::(\w+)\(\)/', $function, $m) === 1 && isset(self::PAGING[$m[1]]);
    }

    private function isApp(string $path): bool
    {
        foreach ($this->roots as $root) {
            if (self::startsWith($path, $root.'/')) {
                $rest = substr($path, strlen($root) + 1);

                return ! str_starts_with(strtolower($rest), 'vendor/') && ! str_contains(strtolower($rest), '/vendor/');
            }
        }

        return false;
    }

    private function isOwn(string $path): bool
    {
        return self::startsWith($path, $this->own.'/');
    }

    private static function isCompiledView(string $path): bool
    {
        return str_contains($path, '/framework/views/') && str_ends_with($path, '.php');
    }

    private static function inVendor(string $path): bool
    {
        return str_contains(strtolower($path), '/vendor/');
    }

    /** @param array<string, mixed> $frame */
    private static function isFrameworkInternal(string $path, array $frame): bool
    {
        $class = (string) ($frame['class'] ?? '');

        return str_contains($path, '/laravel/framework/')
            || str_contains(strtolower($path), '/illuminate/')
            || str_starts_with($class, 'Illuminate'.chr(92))
            || str_starts_with($class, 'Lantern'.chr(92));
    }

    private function relative(string $path): string
    {
        return $this->base !== '' && self::startsWith($path, $this->base.'/')
            ? substr($path, strlen($this->base) + 1)
            : $path;
    }

    private function lineCount(string $file): int
    {
        $contents = @file_get_contents($file);

        return $contents === false ? 0 : substr_count($contents, "\n") + 1;
    }

    private static function startsWith(string $path, string $prefix): bool
    {
        if (str_starts_with($path, $prefix)) {
            return true;
        }

        return (DIRECTORY_SEPARATOR === chr(92) || preg_match('~^[a-z]:/~i', $prefix) === 1)
            && strncasecmp($path, $prefix, strlen($prefix)) === 0;
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace(chr(92), '/', $path), '/');
    }
}
