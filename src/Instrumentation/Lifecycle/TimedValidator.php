<?php

declare(strict_types=1);

namespace Lantern\Instrumentation\Lifecycle;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Validation\Validator;
use Lantern\Trace\Recorder;
use Lantern\Trace\SpanType;

/** Validator that records the first rule run (passes()) as a span. */
final class TimedValidator extends Validator
{
    private bool $timed = false;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $messages
     * @param array<string, mixed> $attributes
     * @param Closure(): Recorder $recorder
     */
    public function __construct(Translator $translator, array $data, array $rules, array $messages, array $attributes, private readonly Closure $recorder)
    {
        parent::__construct($translator, $data, $rules, $messages, $attributes);
    }

    public function passes()
    {
        if ($this->timed) {
            return parent::passes();
        }

        $this->timed = true;
        $recorder = ($this->recorder)();

        if (! $recorder->isActive()) {
            return parent::passes();
        }

        $span = $recorder->start(SpanType::Validation, 'validator', ['kind' => 'validator', 'rules' => count($this->rules)]);

        try {
            return parent::passes();
        } finally {
            $recorder->stop($span);
        }
    }
}
