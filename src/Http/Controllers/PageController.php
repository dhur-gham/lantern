<?php

declare(strict_types=1);

namespace Lantern\Http\Controllers;

use Illuminate\Contracts\View\View;

final class PageController
{
    public function traces(): View
    {
        return $this->page('lantern::traces');
    }

    public function trace(string $id): View
    {
        return $this->page('lantern::trace', ['id' => $id]);
    }

    public function issues(): View
    {
        return $this->page('lantern::issues');
    }

    public function issue(string $key): View
    {
        return $this->page('lantern::issue', ['key' => $key]);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data = []): View
    {
        $base = '/'.trim((string) config('lantern.path', 'lantern'), '/');

        return view($view, $data + ['lantern_base' => $base, 'basePath' => $base, 'apiPath' => $base.'/api']);
    }
}
