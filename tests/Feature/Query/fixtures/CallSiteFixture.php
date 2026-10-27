<?php

declare(strict_types=1);

namespace Lantern\Tests\Feature\Query\fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class FxAuthor extends Model
{
    protected $table = 'authors';

    public $timestamps = false;
}

class FxPost extends Model
{
    protected $table = 'posts';

    public $timestamps = false;

    public function author(): BelongsTo
    {
        return $this->belongsTo(FxAuthor::class, 'author_id');
    }
}

class CallSiteFixture
{
    public function plain(): array
    {
        return DB::select('select 1 as one'); // MARK:plain
    }

    public function lazy(): mixed
    {
        $post = FxPost::query()->first();
        $this->idle();

        return $post->author; // MARK:lazy
    }

    private function idle(): void
    {
    }

    public static function line(string $marker): int
    {
        foreach (file(__FILE__) as $i => $text) {
            if (str_ends_with(rtrim($text), '// MARK:'.$marker)) {
                return $i + 1;
            }
        }

        throw new \RuntimeException('marker');
    }
}
