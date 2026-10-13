<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

/** Internal only: raw supplier payload must never be serialized as an API/job receipt. */
final readonly class Response
{
    public function __construct(public int $status, public ?array $data) {}
}
