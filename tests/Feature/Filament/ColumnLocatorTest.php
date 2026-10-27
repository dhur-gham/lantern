<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Filament;

use Lantern\Instrumentation\Filament\ColumnLocator;
use PHPUnit\Framework\TestCase;

final class ColumnLocatorTest extends TestCase
{
    private function file(): string
    {
        return __DIR__.'/fixtures/app/Filament/Resources/PostResource/Pages/ListPosts.php';
    }

    public function test_finds_column_from_state_closure_line(): void
    {
        $locator = new ColumnLocator;

        $this->assertSame('author_name', $locator->locate($this->file(), 12));
        $this->assertSame('status', $locator->locate($this->file(), 14));
    }

    public function test_finds_column_on_make_line(): void
    {
        $this->assertSame('title', (new ColumnLocator)->locate($this->file(), 9));
    }

    public function test_null_when_no_column_or_bad_input(): void
    {
        $locator = new ColumnLocator;
        $widget = __DIR__.'/fixtures/app/Filament/Widgets/StatsWidget.php';

        $this->assertNull($locator->locate($widget, 9));
        $this->assertNull($locator->locate(__DIR__.'/missing.php', 3));
        $this->assertNull($locator->locate($this->file(), 9999));
    }

    public function test_find_returns_the_line_of_a_named_column(): void
    {
        $locator = new ColumnLocator;

        $this->assertSame(10, $locator->find($this->file(), 'title'));
        $this->assertNull($locator->find($this->file(), 'nope'));
        $this->assertNull($locator->find(__DIR__.'/missing.php', 'title'));
    }
}
