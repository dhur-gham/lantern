<?php

declare(strict_types=1);

namespace Lantern\Trace;

enum SpanType: string
{
    case Request = 'request';
    case Bootstrap = 'bootstrap';
    case Middleware = 'middleware';
    case Route = 'route';
    case Controller = 'controller';
    case Validation = 'validation';
    case Query = 'query';
    case View = 'view';
    case Livewire = 'livewire';
    case Cache = 'cache';
    case Http = 'http';
    case Event = 'event';
    case Job = 'job';
    case Command = 'command';
    case Record = 'record';
}
