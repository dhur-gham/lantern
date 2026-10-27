<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Filament;

use Throwable;

/**
 * Best-effort: names the table column / entry whose definition encloses a call-site line, by scanning the
 * source around it for Column::make('name'). File reads are cached for the life of the instance.
 */
final class ColumnLocator
{
    private const UP = 40;

    private const DOWN = 12;

    private const MAKE = '/\b\w*(?:Column|Entry|Field|Input|Select)::make\(\s*[\'"]([^\'"]+)[\'"]/';

    /** @var array<string, list<string>|null> */
    private array $files = [];

    public function locate(string $file, int $line): ?string
    {
        try {
            $lines = $this->lines($file);
            if ($lines === null || $line < 1 || $line > count($lines)) {
                return null;
            }

            $index = $line - 1;

            // The call site sits on (or inside) a column definition: nearest make() at or above wins.
            for ($i = $index; $i >= max(0, $index - self::UP); $i--) {
                if (preg_match(self::MAKE, $lines[$i], $m) === 1) {
                    return $m[1];
                }
            }

            // Closure bodies defined before the make() call, e.g. a ->state() chain written first.
            for ($i = $index; $i <= min(count($lines) - 1, $index + self::DOWN); $i++) {
                if (preg_match(self::MAKE, $lines[$i], $m) === 1) {
                    return $m[1];
                }
            }
        } catch (Throwable) {
            // best effort
        }

        return null;
    }

    /** Line number (1-based) of Column::make('<name>') in a file, or null. */
    public function find(string $file, string $name): ?int
    {
        try {
            foreach ($this->lines($file) ?? [] as $i => $text) {
                if (preg_match(self::MAKE, $text, $m) === 1 && $m[1] === $name) {
                    return $i + 1;
                }
            }
        } catch (Throwable) {
            // best effort
        }

        return null;
    }

    /** @return list<string>|null */
    private function lines(string $file): ?array
    {
        if (! array_key_exists($file, $this->files)) {
            $contents = is_file($file) ? @file_get_contents($file) : false;
            $this->files[$file] = $contents === false ? null : (preg_split('/\R/', $contents) ?: []);
        }

        return $this->files[$file];
    }
}
