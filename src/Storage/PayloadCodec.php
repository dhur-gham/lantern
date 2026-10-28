<?php

declare(strict_types=1);

namespace Lantern\Storage;

final class PayloadCodec
{
    public const JSON_FLAGS = JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES;

    private const DEPTH = 4096;

    /** @param array<string, mixed> $data */
    public static function encode(array $data, bool $compress): string
    {
        $json = json_encode($data, self::JSON_FLAGS, self::DEPTH);
        if ($json === false) {
            $json = '{}';
        }

        return $compress && function_exists('gzencode') ? 'gz:'.base64_encode((string) gzencode($json, 6)) : $json;
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
