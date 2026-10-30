<?php

declare(strict_types=1);

namespace Lantern\Storage;

/** Content hash of the file a finding's call site points to; tells a code change apart from a data difference. */
final class SiteHash
{
    /** @var array<string, ?string> */
    private static array $cache = [];

    /** @param array{file: string, line: int, function?: string}|null $site */
    public static function of(?array $site): ?string
    {
        if ($site === null) {
            return null;
        }
        $file = $site['file'];
        $path = preg_match('#^(?:[A-Za-z]:)?[\\\\/]#', $file) === 1 ? $file : base_path($file);
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return null;
        }

        $key = $path.'|'.$mtime;

        return self::$cache[$key] ??= (md5_file($path) ?: null);
    }
}
