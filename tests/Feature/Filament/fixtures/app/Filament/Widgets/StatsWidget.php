<?php

namespace App\Filament\Widgets;

class StatsWidget
{
    public function stats()
    {
        return Post::count();
    }
}
