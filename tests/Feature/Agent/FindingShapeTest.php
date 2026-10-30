<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Agent;

use Lantern\Agent\Findings;
use Lantern\Tests\TestCase;

final class FindingShapeTest extends TestCase
{
    public function test_closure_line_numbers_do_not_change_the_shape(): void
    {
        $make = static fn (int $a, int $b, int $line): array => [
            'type' => 'n_plus_one',
            'meta' => ['fingerprint' => 'abc'],
            'call_site' => ['file' => 'app/Jobs/Run.php', 'line' => $line, 'function' => "{closure:App\Jobs\Run::handle():$a}:$b}"],
        ];

        $this->assertSame(Findings::shape($make(58, 61, 61)), Findings::shape($make(70, 73, 73)));
    }
}
