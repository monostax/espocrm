<?php

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Tools;

/** One monotonic budget shared by context resolution, pagination, upload and submission. */
class TemplateDeadline
{
    private float $end;

    public function __construct(float $seconds = 120)
    {
        $this->end = $this->now() + $seconds;
    }

    protected function now(): float
    {
        return hrtime(true) / 1e9;
    }

    public function remaining(): float
    {
        return max(0, $this->end - $this->now());
    }

    public function milliseconds(float $callLimit = 10): int
    {
        $milliseconds = (int) floor(min($callLimit, $this->remaining()) * 1000);
        if ($milliseconds < 1) {
            throw new TemplateFailure('deadline', 'Template operation deadline exceeded.', 504);
        }
        return $milliseconds;
    }
}
