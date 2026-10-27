<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Query;

use Lantern\Query\Fingerprinter;
use PHPUnit\Framework\TestCase;

final class FingerprintCacheTest extends TestCase
{
    public function test_cached_normalization_is_stable_and_survives_cache_overflow(): void
    {
        $f = new Fingerprinter;
        $sql = 'SELECT * FROM `posts` WHERE id IN (1, 2, 3) AND x = \'a\'';
        $first = $f->normalize($sql);

        for ($i = 0; $i < 600; $i++) {
            $f->normalize("select $i from t where a = $i");
        }

        $this->assertSame($first, $f->normalize($sql));
        $this->assertSame('select * from `posts` where id in (?)and x = ?', $first);
        $this->assertSame($f->fingerprint($sql), $f->fingerprint('select * from `posts` where id in (4,5) and x = \'zz\''));
    }
}
