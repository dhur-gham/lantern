<?php

declare(strict_types=1);

namespace Lantern\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Lantern\Lantern;

final class Authorize
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless(Lantern::check($request), 403);

        return $next($request);
    }
}
