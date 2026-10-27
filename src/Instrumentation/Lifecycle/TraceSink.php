<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Lifecycle;

use Lantern\Trace\Trace;

interface TraceSink
{
    public function push(Trace $trace): void;
}
