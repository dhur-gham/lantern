<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Lifecycle;

final class ViewNames
{
    /** Turn a source view path into a dotted view name when it lives under a registered view path. */
    public static function fromPath(string $path): string
    {
        $normalized = self::normalize($path);

        $roots = array_map(self::normalize(...), array_filter((array) config('view.paths', []), 'is_string'));
        $roots[] = self::normalize(resource_path('views'));

        foreach ($roots as $root) {
            if ($root !== '' && str_starts_with($normalized, $root.'/')) {
                return self::dotted(substr($normalized, strlen($root) + 1));
            }
        }

        $base = self::normalize(base_path());
        if (str_starts_with($normalized, $base.'/')) {
            return substr($normalized, strlen($base) + 1);
        }

        return basename($normalized);
    }

    /** Read the source path from the footer marker of a compiled Blade file. */
    public static function sourceFromCompiled(string $compiledPath): ?string
    {
        $size = @filesize($compiledPath);
        if ($size === false) {
            return null;
        }

        $handle = @fopen($compiledPath, 'rb');
        if ($handle === false) {
            return null;
        }

        fseek($handle, max(0, $size - 512));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        return preg_match('~/\*\*PATH (.*?) ENDPATH\*\*/~', $tail, $m) === 1 ? $m[1] : null;
    }

    public static function fromCompiled(string $compiledPath): ?string
    {
        $source = self::sourceFromCompiled($compiledPath);

        return $source === null ? null : self::fromPath($source);
    }

    private static function dotted(string $relative): string
    {
        $relative = preg_replace('~\.blade\.php$|\.php$~', '', $relative) ?? $relative;

        return str_replace('/', '.', $relative);
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
