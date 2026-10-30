<?php

declare(strict_types=1);

namespace Lantern\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Lantern\Recording;

/**
 * @method static mixed record(string $name, Closure $fn, array $context = [])
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
