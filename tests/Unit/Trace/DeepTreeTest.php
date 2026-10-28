<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Trace;

use Lantern\Storage\PayloadCodec;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;
use PHPUnit\Framework\TestCase;

final class DeepTreeTest extends TestCase
{
    public function test_deep_tree_is_truncated_and_round_trips(): void
    {
        $r = new Recorder();
        $trace = $r->begin(['a' => 1], 0);
        for ($i = 0; $i < 600; $i++) {
            $r->start(SpanType::Event, 'd'.$i);
        }

        $array = $trace->toArray();
        $decoded = PayloadCodec::decode(PayloadCodec::encode($array, true));

        $this->assertSame($trace->id, $decoded['id']);
        $node = $decoded['root'];
        $depth = 1;
        while ($node['children'] !== []) {
            $node = $node['children'][0];
            $depth++;
        }
        $this->assertSame(100, $depth);
        $this->assertSame(1, $node['children_omitted']);
    }

    public function test_malformed_payloads_decode_to_null(): void
    {
        foreach (['gz:', 'gz:!!!', 'gz:'.base64_encode('nope'), 'not json'] as $payload) {
            $this->assertNull(PayloadCodec::decode($payload));
        }
    }
}
