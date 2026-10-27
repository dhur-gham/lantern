<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Filament;

use Lantern\Instrumentation\Filament\FilamentInstrumentation;
use Lantern\Tests\TestCase;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;

final class FilamentInstrumentationTest extends TestCase
{
    public function test_annotates_query_spans_in_filament_files(): void
    {
        $recorder = new Recorder;
        $trace = $recorder->begin();
        $this->app->setBasePath(str_replace('\\', '/', __DIR__.'/fixtures'));

        $in = $recorder->recordClosed(SpanType::Query, 'q1', 1, 2, [], ['file' => 'app/Filament/Resources/PostResource/Pages/ListPosts.php', 'line' => 12]);
        $widget = $recorder->recordClosed(SpanType::Query, 'q2', 1, 2, [], ['file' => 'app/Filament/Widgets/StatsWidget.php', 'line' => 9]);
        $other = $recorder->recordClosed(SpanType::Query, 'q3', 1, 2, [], ['file' => 'app/Models/Post.php', 'line' => 3]);

        (new FilamentInstrumentation($this->app))->annotate($trace);

        $this->assertSame('PostResource', $in->meta['filament']['resource']);
        $this->assertSame('App\\Filament\\Resources\\PostResource\\Pages\\ListPosts', $in->meta['filament']['page']);
        $this->assertSame('author_name', $in->meta['filament']['column']);
        $this->assertSame('App\\Filament\\Widgets\\StatsWidget', $widget->meta['filament']['widget']);
        $this->assertArrayNotHasKey('column', $widget->meta['filament']);
        $this->assertArrayNotHasKey('filament', $other->meta);
    }

    public function test_model_accessor_query_is_matched_to_the_resource_column(): void
    {
        $recorder = new Recorder;
        $trace = $recorder->begin();
        $trace->meta['livewire'] = ['App\Filament\Resources\PostResource\Pages\ListPosts'];
        $this->app->setBasePath(str_replace('\\', '/', __DIR__.'/fixtures'));

        $site = ['file' => 'app/Models/Post.php', 'line' => 24, 'function' => '{closure:App\\Models\\Post::latestCommentExcerpt():23}'];
        $accessor = $recorder->recordClosed(SpanType::Query, 'q1', 1, 2, [], $site);
        $plain = $recorder->recordClosed(SpanType::Query, 'q2', 1, 2, [], ['file' => 'app/Models/Post.php', 'line' => 9, 'function' => 'boot']);

        (new FilamentInstrumentation($this->app))->annotate($trace);

        $this->assertSame('PostResource', $accessor->meta['filament']['resource']);
        $this->assertSame('latest_comment_excerpt', $accessor->meta['filament']['column']);
        $this->assertSame('app/Filament/Resources/PostResource.php:11', $accessor->meta['filament']['source']);
        $this->assertArrayNotHasKey('filament', $plain->meta);
    }

    public function test_describe_handles_relation_managers_and_foreign_paths(): void
    {
        $info = FilamentInstrumentation::describe('C:\\x\\app\\Filament\\Resources\\Posts\\RelationManagers\\CommentsRelationManager.php');
        $this->assertSame('Posts', $info['resource']);
        $this->assertSame('App\\Filament\\Resources\\Posts\\RelationManagers\\CommentsRelationManager', $info['relation_manager']);
        $this->assertNull(FilamentInstrumentation::describe('app/Http/Controllers/A.php'));
    }
}
