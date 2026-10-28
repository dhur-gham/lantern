<?php

declare(strict_types=1);

namespace Lantern\Tests\Stress;

use Lantern\Query\Fingerprinter;
use PHPUnit\Framework\TestCase;

final class FingerprintPropertyTest extends TestCase
{
    private Fingerprinter $f;

    protected function setUp(): void
    {
        $this->f = new Fingerprinter;
        mt_srand(20260101);
    }

    private function literal(): string
    {
        return match (mt_rand(0, 9)) {
            0 => (string) mt_rand(-1000000, 1000000),
            1 => (string) (mt_rand(0, 100000) / 100),
            2 => "'".bin2hex(random_bytes(mt_rand(1, 12)))."'",
            3 => "'it''s ".mt_rand()."'",
            4 => "'héllo wörld ".mt_rand()." 日本語'",
            5 => "'--not a comment ".mt_rand()."'",
            6 => "'/* not a comment */ ".mt_rand()."'",
            7 => '0x'.dechex(mt_rand()),
            8 => sprintf('%de%d', mt_rand(1, 9), mt_rand(1, 9)),
            default => "'".str_repeat('a', mt_rand(0, 300))."'",
        };
    }

    public function test_random_literals_never_change_the_fingerprint(): void
    {
        $template = 'select * from users where id = %s and name = %s and age > %s order by id';
        $reference = $this->f->fingerprint(sprintf($template, '1', "'x'", '2'));

        for ($i = 0; $i < 300; $i++) {
            $sql = sprintf($template, $this->literal(), $this->literal(), $this->literal());
            $this->assertSame($reference, $this->f->fingerprint($sql), $sql);
        }
    }

    public function test_random_in_lists_of_any_length_share_one_fingerprint(): void
    {
        $reference = $this->f->fingerprint('select * from t where id in (1) and x = 2');

        for ($i = 0; $i < 100; $i++) {
            $n = mt_rand(1, 200);
            $items = [];
            for ($j = 0; $j < $n; $j++) {
                $items[] = $this->literal();
            }
            $sql = 'select * from t where id in ('.implode(', ', $items).') and x = '.$this->literal();
            $this->assertSame($reference, $this->f->fingerprint($sql), 'IN list of '.$n);
        }
    }

    public function test_random_multi_row_inserts_share_one_fingerprint(): void
    {
        $reference = $this->f->fingerprint('insert into t (a, b) values (1, 2)');

        for ($i = 0; $i < 100; $i++) {
            $rows = [];
            for ($j = 0, $n = mt_rand(1, 50); $j < $n; $j++) {
                $rows[] = '('.$this->literal().', '.$this->literal().')';
            }
            $this->assertSame($reference, $this->f->fingerprint('insert into t (a, b) values '.implode(', ', $rows)));
        }
    }

    public function test_placeholder_styles_share_a_fingerprint_with_literals(): void
    {
        $this->assertSame(
            $this->f->fingerprint('select * from t where a = ? and b = ?'),
            $this->f->fingerprint('select * from t where a = 5 and b = \'z\''),
        );
        $this->assertSame(
            $this->f->fingerprint('select * from t where a = $1 and b = $2'),
            $this->f->fingerprint('select * from t where a = ? and b = ?'),
        );
    }

    public function test_normalize_is_idempotent(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $sql = 'select '.$this->literal().' from t where a in ('.$this->literal().', '.$this->literal().') -- c'."\n".' and b = '.$this->literal();
            $once = $this->f->normalize($sql);
            $this->assertSame($once, $this->f->normalize($once), $sql);
        }
    }

    public function test_whitespace_case_and_comments_do_not_matter(): void
    {
        $a = $this->f->fingerprint('SELECT   *  FROM `Users` WHERE id = 1');
        $b = $this->f->fingerprint("select *\n\tfrom `Users` /* hint */ where id = 2 -- trailing");

        $this->assertSame($a, $b);
    }

    public function test_spacing_around_operators_does_not_change_the_fingerprint(): void
    {
        $this->assertSame($this->f->fingerprint('select * from t where id = 1 and x >= 2'), $this->f->fingerprint('select * from t where id=1 and x>=2'));
    }

    public function test_structural_differences_change_the_fingerprint(): void
    {
        $base = $this->f->fingerprint('select * from users where id = 1');
        foreach ([
            'select * from posts where id = 1',
            'select id from users where id = 1',
            'select * from users where name = 1',
            'select * from users where id > 1',
            'select * from users where id = 1 limit 1',
            'delete from users where id = 1',
            'select * from users where id = 1 for update',
        ] as $other) {
            $this->assertNotSame($base, $this->f->fingerprint($other), $other);
        }
    }

    public function test_quoted_identifiers_are_case_sensitive_but_keywords_are_not(): void
    {
        $this->assertNotSame($this->f->fingerprint('select "A" from t'), $this->f->fingerprint('select "a" from t'));
        $this->assertSame($this->f->fingerprint('SELECT a FROM t'), $this->f->fingerprint('select A from T'));
    }

    public function test_negative_numbers_equal_positive_ones(): void
    {
        $this->assertSame($this->f->fingerprint('select * from t where a = -5'), $this->f->fingerprint('select * from t where a = 5'));
        $this->assertSame($this->f->fingerprint('select * from t where a in (-1,-2,3)'), $this->f->fingerprint('select * from t where a in (9)'));
    }

    public function test_digits_inside_identifiers_are_kept(): void
    {
        $this->assertNotSame($this->f->fingerprint('select * from t1'), $this->f->fingerprint('select * from t2'));
        $this->assertNotSame($this->f->fingerprint('select col1 from t'), $this->f->fingerprint('select col2 from t'));
    }

    public function test_unterminated_quote_comment_and_garbage_do_not_throw(): void
    {
        foreach (["select 'unterminated", 'select /* never closed', 'select -- only comment', '"', '`', "'", '[', '$', '', ' ', "\0", "\xFF\xFE", 'select ' . str_repeat('(', 5000)] as $sql) {
            $this->assertIsString($this->f->normalize($sql));
            $this->assertNotSame('', $this->f->fingerprint($sql));
        }
    }

    public function test_random_garbage_never_throws_and_stays_deterministic(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $sql = random_bytes(mt_rand(1, 200));
            $this->assertSame($this->f->fingerprint($sql), (new Fingerprinter)->fingerprint($sql));
        }
    }

    public function test_cache_eviction_does_not_change_results(): void
    {
        $first = $this->f->normalize('select * from t where id = 1');
        for ($i = 0; $i < 2000; $i++) {
            $this->f->normalize('select '.$i.' as c'.$i);
        }

        $this->assertSame($first, $this->f->normalize('select * from t where id = 1'));
    }

    public function test_in_list_of_ten_thousand_items_collapses(): void
    {
        $this->assertSame('select * from t where id in (?)', $this->f->normalize('select * from t where id in ('.implode(',', range(1, 10000)).')'));
    }

    public function test_huge_in_lists_on_different_tables_get_different_fingerprints(): void
    {
        $ids = implode(',', range(1, 50000));

        $this->assertNotSame($this->f->fingerprint("select * from a where id in ($ids)"), $this->f->fingerprint("select * from b where id in ($ids)"));
    }

    public function test_huge_in_list_of_fifty_thousand_items_collapses(): void
    {
        $sql = 'select * from t where id in ('.implode(',', range(1, 50000)).')';

        $this->assertSame('select * from t where id in (?)', $this->f->normalize($sql));
    }

    public function test_huge_multi_row_insert_collapses(): void
    {
        $rows = implode(',', array_map(fn ($i) => "($i,'x$i')", range(1, 40000)));

        $this->assertSame('insert into t(a,b)values(?,?)', $this->f->normalize('insert into t (a, b) values '.$rows));
    }

    public function test_large_sql_normalizes_quickly(): void
    {
        $sql = 'select '.str_repeat("'abc', 12345, ", 20000).' 1';
        $t = microtime(true);

        $this->f->normalize($sql);

        $this->assertLessThan(5.0, microtime(true) - $t);
    }

    public function test_backslash_escaped_quote_in_a_mysql_literal_stays_one_literal(): void
    {
        $a = $this->f->fingerprint("select * from t where name = 'it\\'s' and id = 1");
        $b = $this->f->fingerprint("select * from t where name = 'x' and id = 1");

        $this->assertSame($b, $a);
    }

    public function test_bindings_key_distinguishes_values_and_order(): void
    {
        $sql = 'select * from t where a = ? and b = ?';

        $this->assertSame($this->f->bindingsKey($sql, [1, 2]), $this->f->bindingsKey($sql, [1, 2]));
        $this->assertNotSame($this->f->bindingsKey($sql, [1, 2]), $this->f->bindingsKey($sql, [2, 1]));
        $this->assertNotSame($this->f->bindingsKey($sql, [1, 2]), $this->f->bindingsKey($sql, ['1', '2']));
        $this->assertNotSame($this->f->bindingsKey($sql, [null]), $this->f->bindingsKey($sql, ['']));
    }

    public function test_bindings_key_survives_unserialisable_bindings(): void
    {
        $closure = fn () => 1;

        $this->assertIsString($this->f->bindingsKey('select ?', [$closure]));
        $this->assertIsString($this->f->bindingsKey('select ?', [fopen('php://memory', 'r')]));
    }
}
