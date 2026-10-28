<?php

declare(strict_types=1);

namespace Lantern\Agent;

use RuntimeException;

final class AgentException extends RuntimeException
{
    public const USAGE = 'usage_error';

    public const CONFIG = 'config_error';

    public const NO_DATA = 'no_data';

    public const NOT_FOUND = 'not_found';

    public const STORAGE = 'storage_error';

    public function __construct(public readonly string $errorCode, string $message, public readonly int $exitCode, public readonly ?string $hint = null)
    {
        parent::__construct($message);
    }

    public static function usage(string $message, ?string $hint = null): self
    {
        return new self(self::USAGE, $message, 2, $hint);
    }

    public static function config(string $message, ?string $hint = null): self
    {
        return new self(self::CONFIG, $message, 2, $hint);
    }

    public static function noData(string $message, ?string $hint = null): self
    {
        return new self(self::NO_DATA, $message, 3, $hint);
    }

    public static function notFound(string $message, ?string $hint = null): self
    {
        return new self(self::NOT_FOUND, $message, 2, $hint);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'schema_version' => Findings::VERSION,
            'error' => array_filter([
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'hint' => $this->hint,
                'exit_code' => $this->exitCode,
            ], fn ($v) => $v !== null),
        ];
    }
}
