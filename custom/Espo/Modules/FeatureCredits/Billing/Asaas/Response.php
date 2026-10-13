<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing\Asaas;

final readonly class Response
{
    public function __construct(public int $status, public ?array $data) {}
}
