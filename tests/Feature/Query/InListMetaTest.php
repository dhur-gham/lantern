<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;

final class InListMetaTest extends QueryTestCase
{
    public function test_query_spans_carry_the_in_list_length_without_changing_the_fingerprint(): void
    {
        $this->seedTables();
        $this->recorder()->begin();

        DB::select('select * from q_authors where id in (?, ?, ?)', [1, 2, 3]);
        DB::select('select * from q_authors where id in (?, ?, ?, ?, ?)', [1, 2, 3, 4, 5]);
        DB::select('select * from q_authors where id = ?', [1]);

        [$small, $large, $plain] = $this->queries($this->recorder()->trace());
        $this->assertSame(3, $small->meta['in_size']);
        $this->assertSame(5, $large->meta['in_size']);
        $this->assertSame($small->meta['fingerprint'], $large->meta['fingerprint']);
        $this->assertArrayNotHasKey('in_size', $plain->meta);
    }
}
