<?php

declare(strict_types=1);

namespace Lantern\Access;

/** App-owned minting of credentials for a guard Lantern cannot know how to log into (custom Auth::extend guards). */
interface ProvidesCredentials
{
    /**
     * @param array<string, mixed> $subject the surface's `subject` (for example ['merchant' => 222])
     * @return array{headers: array<string, string>, query?: array<string, string>, expires_at?: \DateTimeInterface}
     */
    public function issue(array $subject): array;

    /** Delete whatever issue() created. @param array<string, mixed> $issued the array issue() returned */
    public function revoke(array $issued): void;
}
