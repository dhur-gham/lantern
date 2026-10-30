<?php

declare(strict_types=1);

namespace Lantern\Access;

use Illuminate\Contracts\Auth\Authenticatable;

/** Optional on a credentials class: lets Lantern::actingAs() sign a custom guard in for in-process code. */
interface ProvidesUser
{
    /** @param array<string, mixed> $subject */
    public function user(array $subject): Authenticatable;
}
