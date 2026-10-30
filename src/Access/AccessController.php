<?php

declare(strict_types=1);

namespace Lantern\Access;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AccessController
{
    private const FORWARDING = ['X-Forwarded-For', 'Forwarded', 'X-Forwarded-Host', 'X-Real-IP'];

    public function __invoke(Request $request, Access $access, string $token): RedirectResponse
    {
        foreach (self::FORWARDING as $header) {
            abort_if($request->headers->has($header), 403); // a proxy or tunnel is not a local browser
        }
        abort_unless(Access::available(app()), 404);

        $to = $access->consume($token, $request);
        abort_if($to === null, 404); // unknown, used and expired links look the same

        return redirect($to);
    }
}
