<?php

declare(strict_types=1);

namespace Lantern\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Lantern\Recording;

/**
 * @method static mixed record(string $name, Closure $fn, array $context = [])
 * @method static \Illuminate\Contracts\Auth\Authenticatable actingAs(string $surface, ?string $user = null)
 *
 * @see Recording
 */
final class Lantern extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Recording::class;
    }
}
