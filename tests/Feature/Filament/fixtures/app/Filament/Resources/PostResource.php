<?php

namespace App\Filament\Resources;

class PostResource
{
    public static function table($table)
    {
        return $table->columns([
            TextColumn::make('title'),
            TextColumn::make('latest_comment_excerpt'),
        ]);
    }
}
