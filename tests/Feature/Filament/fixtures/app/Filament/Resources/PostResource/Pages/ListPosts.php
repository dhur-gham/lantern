<?php

namespace App\Filament\Resources\PostResource\Pages;

class ListPosts
{
    public function table($table)
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
            TextColumn::make('author_name')
                ->label('Author')
                ->state(fn ($record) => $record->author->name),
            TextColumn::make("status")
                ->getStateUsing(fn ($record) => $record->comments()->count()),
        ]);
    }
}
