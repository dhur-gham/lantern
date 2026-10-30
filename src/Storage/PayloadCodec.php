<?php

declare(strict_types=1);

namespace Lantern\Storage;

final class PayloadCodec
{
    public const JSON_FLAGS = JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES;

    private const DEPTH = 4096;

    /** Encoded size above which long strings are cut down. */
    public const MAX_BYTES = 4_194_304;

    private const MAX_STRING = 8192;

    /** @param array<string, mixed> $data */
    public static function encode(array $data, bool $compress): string
    {
        $json = json_encode($data, self::JSON_FLAGS, self::DEPTH);
        if ($json === false) {
            $json = '{}';
        }

        if (strlen($json) > self::MAX_BYTES) {
            $json = self::shrink($data, strlen($json));
        }

        if (! $compress || ! function_exists('gzencode')) {
            return $json;
        }

        $gz = (string) gzencode($json, 6);
        unset($json);

        return 'gz:'.base64_encode($gz);
    }

    /** @param array<string, mixed> $data */
    private static function shrink(array $data, int $original): string
    {
        array_walk_recursive($data, static function (&$value): void {
            if (is_string($value) && strlen($value) > self::MAX_STRING) {
                $value = substr($value, 0, self::MAX_STRING).'...[truncated '.(strlen($value) - self::MAX_STRING).' bytes]';
            }
        });

        $json = json_encode($data, self::JSON_FLAGS, self::DEPTH);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            return (string) json_encode(['truncated' => true, 'original_bytes' => $original]);
        }

        return $json;
    }

    /** @return array<string, mixed>|null null when the payload cannot be decoded */
    public static function decode(string $payload): ?array
    {
        if (str_starts_with($payload, 'gz:')) {
            $raw = base64_decode(substr($payload, 3), true);
            $payload = $raw === false || $raw === '' ? false : @gzdecode($raw);
            if ($payload === false) {
                return null;
            }
        }

        $data = json_decode($payload, true, self::DEPTH);

        return is_array($data) ? $data : null;
    }
}
