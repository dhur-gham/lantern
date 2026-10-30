<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Query;

use Lantern\Query\Fingerprinter;
use Lantern\Query\SqlInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlInputTest extends TestCase
{
    private const SHAPE = 'select * from `users` where `email` = ? and `id` in (?)limit ?';

    /** @return array<string, array{string}> */
    public static function sameAsApp(): array
    {
        return [
            'question marks' => ['select * from `users` where `email` = ? and `id` in (?, ?, ?) limit 10'],
            'python %s' => ['SELECT * FROM `users` WHERE `email` = %s AND `id` IN (%s, %s) LIMIT 10'],
            'named %(x)s' => ['select * from `users` where `email` = %(e)s and `id` in (%(a)s, %(b)s) limit 10'],
            'postgres $n' => ['select * from `users` where `email` = $1 and `id` in ($2, $3) limit 10'],
            'named :x' => ['select * from `users` where `email` = :email and `id` in (:a, :b) limit 10'],
            'literals' => ["select * from `users` where `email` = 'a@b.c' and `id` in (1, 2, 3, 4, 5) limit 10"],
            'whitespace and case' => ["SELECT  *\n FROM `users`\n WHERE `email`='x' AND `id` IN (1,2)  LIMIT 5"],
            'comments and hints' => ["/* req=abc */ select * from `users` where `email` = ? -- trace\n and `id` in (?) limit 1"],
        ];
    }

    #[DataProvider('sameAsApp')]
    public function test_placeholder_styles_normalise_to_the_same_shape(string $sql): void
    {
        $this->assertSame(self::SHAPE, (new Fingerprinter)->normalize(SqlInput::prepare($sql)));
    }

    public function test_quoted_text_comments_and_casts_are_left_alone(): void
    {
        $this->assertSame("select ':a' as x, 'a%s' from t", SqlInput::prepare("select ':a' as x, 'a%s' from t"));
        $this->assertSame('select a::int, b from t', SqlInput::prepare('select a::int, b from t'));
        $this->assertSame('select 1 /* :x %s */', SqlInput::prepare('select 1 /* :x %s */'));
        $this->assertSame('select * from t where a = ?', SqlInput::prepare('select * from t where a = :a'));
    }

    public function test_existing_fingerprints_are_unchanged_by_prepare_for_plain_sql(): void
    {
        $f = new Fingerprinter;
        $sql = 'select * from users where id = ? and name = ?';
        $this->assertSame($f->fingerprint($sql), $f->fingerprint(SqlInput::prepare($sql)));
    }

    public function test_looks_like_sql(): void
    {
        $this->assertTrue(SqlInput::looksLikeSql('  /* c */ SELECT 1'));
        $this->assertTrue(SqlInput::looksLikeSql('(select 1) union (select 2)'));
        $this->assertTrue(SqlInput::looksLikeSql('with a as (select 1) select * from a'));
        $this->assertFalse(SqlInput::looksLikeSql('hello world'));
        $this->assertFalse(SqlInput::looksLikeSql(''));
    }

    public function test_loose_and_similarity(): void
    {
        $this->assertSame(SqlInput::loose('select * from `users`'), SqlInput::loose('SELECT * FROM "users"'));
        $a = SqlInput::loose('select id from users where email = ?');
        $this->assertSame(1.0, SqlInput::similarity($a, $a));
        $this->assertGreaterThan(0.6, SqlInput::similarity($a, SqlInput::loose('select id from users where email = ? and active = ?')));
        $this->assertLessThan(0.3, SqlInput::similarity($a, SqlInput::loose('select * from posts join tags on tags.id = posts.tag_id')));
    }
}
