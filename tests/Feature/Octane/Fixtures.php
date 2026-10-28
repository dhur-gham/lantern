<?php

declare(strict_types=1);

namespace Laravel\Octane\Events {
    if (! class_exists(RequestReceived::class)) {
        class RequestReceived {}
        class RequestTerminated {}
        class TaskReceived {}
        class TickReceived {}
    }
}
