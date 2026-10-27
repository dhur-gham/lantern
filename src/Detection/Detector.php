<?php

declare(strict_types=1);

namespace Lantern\Detection;

use Lantern\Trace\Trace;

interface Detector
{
    /** @return list<Issue> */
    public function detect(Trace $trace): array;
}
