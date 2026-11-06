<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit;

use Lantern\Agent\AgentException;
use Lantern\Scenario\Scenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScenarioValidationTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function base(array $over = []): array
    {
        return $over + ['steps' => [['request' => 'get', 'path' => '/x']]];
    }

    /** @param  array<string, mixed>  $data */
    private function assertRejected(array $data): void
    {
        $this->expectException(AgentException::class);
        Scenario::fromArray($data);
    }

    public function test_valid_minimal_scenario(): void
    {
        $s = Scenario::fromArray($this->base(), 'fallback');

        $this->assertSame('fallback', $s->name);
        $this->assertNull($s->surface);
        $this->assertNull($s->throttle);
        $this->assertSame([], $s->fake);
        $this->assertCount(1, $s->steps);
        $this->assertSame('GET', $s->steps[0]['method']);
        $this->assertFalse($s->hasWrites());
        $this->assertFalse($s->hasUploads());
    }

    public function test_writes_and_uploads_are_detected(): void
    {
        $s = Scenario::fromArray($this->base(['steps' => [
            ['request' => 'POST', 'path' => '/x', 'files' => ['img' => ['name' => 'a.jpg']]],
        ]]));

        $this->assertTrue($s->hasWrites());
        $this->assertTrue($s->hasUploads());
    }

    public function test_unknown_keys_are_rejected(): void
    {
        $this->assertRejected($this->base(['sufrace' => 'panel']));
    }

    public function test_unknown_step_key_is_rejected(): void
    {
        $this->assertRejected(['steps' => [['request' => 'GET', 'path' => '/x', 'bogus' => 1]]]);
    }

    public function test_step_needs_exactly_one_kind(): void
    {
        $this->assertRejected(['steps' => [['path' => '/x']]]);
    }

    public function test_steps_must_be_a_non_empty_list(): void
    {
        $this->assertRejected(['steps' => []]);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badTopLevel(): array
    {
        return [
            'fake nested list' => [['fake' => [[]]]],
            'fake unknown' => [['fake' => ['events']]],
            'fake not list' => [['fake' => 'queue']],
            'throttle string' => [['throttle' => 'no']],
            'surface non-string' => [['surface' => 5]],
            'user empty' => [['user' => '']],
            'session list' => [['session' => [1, 2]]],
            'name bad' => [['name' => 'bad name!']],
        ];
    }

    /** @param  array<string, mixed>  $over */
    #[DataProvider('badTopLevel')]
    public function test_bad_top_level_values(array $over): void
    {
        $this->assertRejected($this->base($over));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badRequestSteps(): array
    {
        $r = ['request' => 'GET', 'path' => '/x'];

        return [
            'as.surface int' => [$r + ['as' => ['surface' => 1]]],
            'as.user int' => [$r + ['as' => ['user' => 7]]],
            'as.surface empty' => [$r + ['as' => ['surface' => '']]],
            'as.via other' => [$r + ['as' => ['via' => 'cookie']]],
            'as unknown key' => [$r + ['as' => ['role' => 'x']]],
            'bad method' => [['request' => 'TRACE', 'path' => '/x']],
            'absolute url' => [['request' => 'GET', 'path' => 'http://evil.test/x']],
            'protocol relative' => [['request' => 'GET', 'path' => '//evil.test/x']],
            'expect out of range' => [$r + ['expect' => 99]],
            'credential header' => [$r + ['headers' => ['Authorization' => 'x']]],
            'cookie header' => [$r + ['headers' => ['X-Session-Id' => 'x']]],
            'json and form' => [['request' => 'POST', 'path' => '/x', 'json' => [], 'form' => ['a' => 'b']]],
            'files on GET' => [$r + ['files' => ['f' => ['name' => 'a.jpg']]]],
        ];
    }

    /** @param  array<string, mixed>  $step */
    #[DataProvider('badRequestSteps')]
    public function test_bad_request_steps(array $step): void
    {
        $this->assertRejected(['steps' => [$step]]);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badFiles(): array
    {
        $post = fn (array $files) => ['request' => 'POST', 'path' => '/x', 'files' => $files];

        return [
            'too many files' => [$post(['f' => array_fill(0, 11, ['name' => 'a.jpg'])])],
            'kb too big' => [$post(['f' => ['name' => 'a.jpg', 'kb' => 10_241]])],
            'width too big' => [$post(['f' => ['name' => 'a.jpg', 'width' => 4_001]])],
            'height zero' => [$post(['f' => ['name' => 'a.jpg', 'height' => 0]])],
            'path traversal name' => [$post(['f' => ['name' => '../a.jpg']])],
            'bad kind' => [$post(['f' => ['name' => 'a.jpg', 'kind' => 'video']])],
            'bad mime' => [$post(['f' => ['name' => 'a.bin', 'mime' => 'nope']])],
            'bad field name' => [$post(['1bad' => ['name' => 'a.jpg']])],
        ];
    }

    /** @param  array<string, mixed>  $step */
    #[DataProvider('badFiles')]
    public function test_upload_limits(array $step): void
    {
        $this->assertRejected(['steps' => [$step]]);
    }

    public function test_upload_at_the_limits_is_accepted(): void
    {
        $s = Scenario::fromArray(['steps' => [['request' => 'POST', 'path' => '/x', 'files' => [
            'f' => array_fill(0, 10, ['name' => 'a.jpg', 'kb' => 10_240, 'width' => 4_000, 'height' => 4_000]),
        ]]]]);

        $this->assertCount(10, $s->steps[0]['files']['f']);
    }

    public function test_livewire_actions_are_validated(): void
    {
        $ok = Scenario::fromArray(['steps' => [['livewire' => 'App\\Foo', 'do' => [
            ['set' => 'a', 'value' => 1], ['call' => 'go', 'args' => [1]],
            ['upload' => 'photo', 'file' => ['name' => 'p.jpg']],
        ]]]]);
        $this->assertTrue($ok->hasWrites());
        $this->assertTrue($ok->hasUploads());

        foreach ([
            [['set' => 'a']],
            [['call' => 'go', 'args' => 'x']],
            [['call' => 'bad name']],
            [['set' => 'a', 'value' => 1, 'call' => 'go']],
            [['upload' => 'p']],
            [['upload' => 'p', 'file' => ['name' => 'a.jpg'], 'files' => [['name' => 'b.jpg']]]],
        ] as $do) {
            try {
                Scenario::fromArray(['steps' => [['livewire' => 'App\\Foo', 'do' => $do]]]);
                $this->fail('accepted '.json_encode($do));
            } catch (AgentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_step_and_action_count_limits(): void
    {
        $do = array_fill(0, Scenario::MAX_ACTIONS + 1, ['call' => 'go']);
        try {
            Scenario::fromArray(['steps' => [['livewire' => 'App\\Foo', 'do' => $do]]]);
            $this->fail('too many actions accepted');
        } catch (AgentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertRejected(['steps' => array_fill(0, Scenario::MAX_STEPS + 1, ['request' => 'GET', 'path' => '/x'])]);
    }

    public function test_from_file_checks_file(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'scn-');
        try {
            file_put_contents($file, '[1,2]');
            try {
                Scenario::fromFile($file);
                $this->fail('list accepted');
            } catch (AgentException) {
                $this->addToAssertionCount(1);
            }

            file_put_contents($file, json_encode($this->base()));
            $this->assertCount(1, Scenario::fromFile($file)->steps);

            $this->expectException(AgentException::class);
            Scenario::fromFile($file.'.missing');
        } finally {
            @unlink($file);
        }
    }

    public function test_effective_fakes_merge_without_duplicates(): void
    {
        $s = Scenario::fromArray($this->base(['fake' => ['mail', 'queue']]));

        $this->assertSame(['queue', 'mail'], $s->effectiveFakes(['queue']));
        $this->assertSame(['http', 'storage'], Scenario::unfakedEffects(['queue', 'mail', 'notification'], true));
    }
}
