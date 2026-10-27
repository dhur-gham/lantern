<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Query;

use Lantern\Query\Fingerprinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sameShape(): array
    {
        $c = 'select * from `comments` where `comments`.`post_id` = ? and `comments`.`post_id` is not null';

        return [
            'string literal vs placeholder' => ["select * from users where name = 'bob'", 'select * from users where name = ?'],
            'string escapes' => ["select * from users where name = 'o''neil'", "select * from users where name = 'x'"],
            'int literal' => ['select * from users where id = 42', 'select * from users where id = ?'],
            'float literal' => ['select * from t where p > 1.5', 'select * from t where p > 99'],
            'negative literal' => ['select * from t where p > -5', 'select * from t where p > ?'],
            'exp literal' => ['select * from t where p > 1e5', 'select * from t where p > 2'],
            'hex literal' => ['select * from t where p = 0xFF', 'select * from t where p = ?'],
            'whitespace' => ["select  *\n from\tusers   where id = ?", 'select * from users where id = ?'],
            'keyword case' => ['SELECT * FROM Users WHERE Id = ?', 'select * from users where id = ?'],
            'block comment' => ['select /* hi */ * from users where id = ?', 'select * from users where id = ?'],
            'line comment' => ["select * from users -- trailing\nwhere id = ?", 'select * from users where id = ?'],
            'pg placeholders' => ['select * from users where id = $1 and a = $2', 'select * from users where id = ? and a = ?'],
            'in list 3 vs 1' => ['select * from users where id in (?, ?, ?)', 'select * from users where id in (?)'],
            'in list literals' => ['select * from users where id in (1,2,3,4)', 'select * from users where id in (?, ?)'],
            'not in list' => ['select * from users where id not in (1, 2)', 'select * from users where id not in (?, ?, ?)'],
            'in list strings' => ["select * from t where k in ('a','b')", 'select * from t where k in (?)'],
            'multi-row insert' => ['insert into t (a, b) values (?, ?), (?, ?), (?, ?)', 'insert into t (a, b) values (?, ?)'],
            'multi-row literals' => ["insert into t (a, b) values (1, 'x'), (2, 'y')", 'insert into t (a, b) values (?, ?)'],
            'limit offset' => ['select * from t limit 10 offset 20', 'select * from t limit ? offset ?'],
            'eloquent children' => [$c, 'SELECT * FROM `comments` WHERE `comments`.`post_id` = 7 AND `comments`.`post_id` IS NOT NULL'],
            'count aggregate' => ['select count(*) as aggregate from `posts` where `user_id` = 3', 'select count(*) as aggregate from `posts` where `user_id` = ?'],
            'whereHas exists' => [
                'select * from `posts` where exists (select * from `comments` where `posts`.`id` = `comments`.`post_id` and `body` like ?)',
                "select * from `posts` where exists (select * from `comments` where `posts`.`id` = `comments`.`post_id` and `body` like '%x%')",
            ],
            'post_tag join' => [
                'select `tags`.*, `post_tag`.`post_id` as `pivot_post_id` from `tags` inner join `post_tag` on `tags`.`id` = `post_tag`.`tag_id` where `post_tag`.`post_id` in (1, 2, 3)',
                'select `tags`.*, `post_tag`.`post_id` as `pivot_post_id` from `tags` inner join `post_tag` on `tags`.`id` = `post_tag`.`tag_id` where `post_tag`.`post_id` in (?)',
            ],
            'pg quoted idents' => ['select "u"."id" from "users" "u" where "u"."id" = $1', 'SELECT "u"."id" FROM "users" "u" WHERE "u"."id" = 5'],
            'sqlite bracket idents' => ['select [id] from [users] where [id] = 1', 'select [id] from [users] where [id] = ?'],
            'string with comment chars' => ["select * from t where a = '-- not /* a comment */'", 'select * from t where a = ?'],
            'paren spacing' => ['select * from t where id in ( ? , ? )', 'select * from t where id in (?)'],
            'order limit' => ['select * from `posts` order by `created_at` desc limit 15 offset 30', 'select * from `posts` order by `created_at` desc limit ? offset ?'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function differentShape(): array
    {
        return [
            'table' => ['select * from users where id = ?', 'select * from posts where id = ?'],
            'column' => ['select * from users where id = ?', 'select * from users where uid = ?'],
            'operator' => ['select * from users where id = ?', 'select * from users where id > ?'],
            'is null vs not null' => ['select * from t where a is null', 'select * from t where a is not null'],
            'in vs eq' => ['select * from t where id in (?)', 'select * from t where id = ?'],
            'quoted ident case' => ['select * from "Users"', 'select * from "users"'],
            'digit in ident' => ['select * from t1 where a = ?', 'select * from t2 where a = ?'],
            'column count in values' => ['insert into t (a) values (?)', 'insert into t (a, b) values (?, ?)'],
            'count vs select' => ['select count(*) from t', 'select * from t'],
            'join type' => ['select * from a inner join b on a.id = b.a_id', 'select * from a left join b on a.id = b.a_id'],
            'backtick ident' => ['select `a` from t', 'select `b` from t'],
        ];
    }

    #[DataProvider('sameShape')]
    public function test_same_fingerprint(string $a, string $b): void
    {
        $f = new Fingerprinter;

        $this->assertSame($f->normalize($a), $f->normalize($b));
        $this->assertSame($f->fingerprint($a), $f->fingerprint($b));
    }

    #[DataProvider('differentShape')]
    public function test_different_fingerprint(string $a, string $b): void
    {
        $f = new Fingerprinter;

        $this->assertNotSame($f->fingerprint($a), $f->fingerprint($b));
    }

    public function test_placeholders_preserved(): void
    {
        $this->assertSame('select * from t where a = ?', (new Fingerprinter)->normalize('select * from t where a = ?'));
    }

    public function test_fingerprint_is_hex_hash(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (new Fingerprinter)->fingerprint('select 1'));
    }

    public function test_bindings_key(): void
    {
        $f = new Fingerprinter;
        $sql = 'select * from users where id = ?';

        $this->assertSame($f->bindingsKey($sql, [1]), $f->bindingsKey($sql, [1]));
        $this->assertNotSame($f->bindingsKey($sql, [1]), $f->bindingsKey($sql, [2]));
        $this->assertNotSame($f->bindingsKey($sql, [1]), $f->bindingsKey('select * from posts where id = ?', [1]));
        $this->assertNotSame($f->bindingsKey($sql, [1]), $f->bindingsKey($sql, ['1']));
    }
}
