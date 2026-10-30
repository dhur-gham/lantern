<?php

declare(strict_types=1);

namespace Lantern\Tests\Unit\Detection;

use Lantern\Detection\Explain\ExplainRunner;
use PHPUnit\Framework\TestCase;

final class ExplainAnalyzeParseTest extends TestCase
{
    public function test_a_finished_run_gives_its_returned_rows(): void
    {
        $plan = "-> Table scan on <temporary>  (cost=2.5..2.5 rows=0) (actual time=0.001..0.004 rows=41 loops=1)\n"
            ."    -> Aggregate using temporary table  (actual time=412..412 rows=41 loops=1)\n"
            ."        -> Nested loop inner join  (cost=1.3e+6 rows=548187) (actual time=2.08..398 rows=1093 loops=1)\n";

        $this->assertSame([41, null], ExplainRunner::readAnalyzedTree($plan, 2000));
    }

    public function test_loops_multiply_the_rows(): void
    {
        $plan = '-> Filter: (a.x = 1)  (cost=1 rows=2) (actual time=0.1..0.2 rows=3 loops=4)';

        $this->assertSame([12, null], ExplainRunner::readAnalyzedTree($plan, 2000));
    }

    public function test_a_run_cut_off_by_the_limit_is_a_timeout(): void
    {
        $plan = "-> Table scan on <temporary>  (never executed)\n"
            ."    -> Aggregate using temporary table  (never executed)\n"
            ."        -> Nested loop inner join  (cost=1.3e+6 rows=548187) (actual time=2.08..1998 rows=1093 loops=1)\n";

        $this->assertSame([null, 'timeout'], ExplainRunner::readAnalyzedTree($plan, 2000));
    }

    public function test_a_top_node_that_ended_at_the_limit_is_a_timeout(): void
    {
        $plan = '-> Table scan on <temporary>  (cost=2.5 rows=0) (actual time=0.001..1990 rows=7 loops=1)';

        $this->assertSame([null, 'timeout'], ExplainRunner::readAnalyzedTree($plan, 2000));
    }

    public function test_an_answer_in_an_unknown_shape_says_parse_failed(): void
    {
        $this->assertSame([null, 'parse_failed'], ExplainRunner::readAnalyzedTree('-> Something new (took 3ms)', 2000));
        $this->assertSame([null, 'parse_failed'], ExplainRunner::readAnalyzedTree('', 2000));
    }
}
