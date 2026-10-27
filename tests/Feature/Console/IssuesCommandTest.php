<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Console;

final class IssuesCommandTest extends ConsoleTestCase
{
    public function test_empty_storage_message(): void
    {
        $this->artisan('lantern:issues')->expectsOutputToContain('No issues recorded yet.')->assertSuccessful();
    }

    public function test_not_configured_message(): void
    {
        config(['lantern.storage.driver' => 'memory']);

        $this->artisan('lantern:issues')->expectsOutputToContain('not configured')->assertFailed();
    }

    public function test_ranked_table_and_filters(): void
    {
        $this->seedIssue('a', 'n_plus_one', 'high', 50.0);
        $this->seedIssue('b', 'slow_query', 'low', 120.0);

        $this->artisan('lantern:issues')
            ->expectsOutputToContain('Title b')
            ->expectsOutputToContain('app/Foo.php:12')
            ->assertSuccessful();

        $this->artisan('lantern:issues', ['--type' => 'n_plus_one'])
            ->expectsOutputToContain('Title a')
            ->doesntExpectOutputToContain('Title b')
            ->assertSuccessful();

        $this->artisan('lantern:issues', ['--severity' => 'low', '--limit' => 1])
            ->expectsOutputToContain('Fix b')
            ->doesntExpectOutputToContain('Title a')
            ->assertSuccessful();
    }
}
