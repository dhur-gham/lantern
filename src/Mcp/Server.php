<?php

declare(strict_types=1);

namespace Lantern\Mcp;

use Laravel\Mcp\Facades\Mcp;

/**
 * Registers the local Lantern MCP server when laravel/mcp is installed.
 * Call from a service provider: \Lantern\Mcp\Server::register();
 */
final class Server
{
    public static function available(): bool
    {
        return class_exists(Mcp::class) && class_exists(\Laravel\Mcp\Server::class);
    }

    public static function register(string $handle = 'lantern'): bool
    {
        if (! self::available()) {
            return false;
        }

        Mcp::local($handle, LanternServer::class);

        return true;
    }
}
