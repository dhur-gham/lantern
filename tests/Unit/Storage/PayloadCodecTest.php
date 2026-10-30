<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Storage;

use Lantern\Storage\PayloadCodec;
use PHPUnit\Framework\TestCase;

final class PayloadCodecTest extends TestCase
{
    public function test_small_payload_round_trips_unchanged(): void
    {
        $data = ['a' => 1, 'sql' => str_repeat('x', 5000)];

        foreach ([false, true] as $compress) {
            $this->assertSame($data, PayloadCodec::decode(PayloadCodec::encode($data, $compress)));
        }
    }

    public function test_oversized_payload_cuts_long_strings_with_a_marker(): void
    {
        $data = ['spans' => []];
        for ($i = 0; $i < 30; $i++) {
            $data['spans'][] = ['sql' => str_repeat('x', 200_000)];
        }

        $encoded = PayloadCodec::encode($data, false);
        $this->assertLessThan(PayloadCodec::MAX_BYTES, strlen($encoded));

        $decoded = PayloadCodec::decode($encoded);
        $this->assertCount(30, $decoded['spans']);
        $this->assertStringContainsString('[truncated', $decoded['spans'][0]['sql']);
    }

    public function test_payload_still_too_large_after_cutting_is_replaced_by_a_marker(): void
    {
        $data = ['spans' => array_fill(0, 700, ['name' => str_repeat('n', 8000)])];

        $decoded = PayloadCodec::decode(PayloadCodec::encode($data, true));

        $this->assertTrue($decoded['truncated']);
        $this->assertGreaterThan(PayloadCodec::MAX_BYTES, $decoded['original_bytes']);
    }
}
