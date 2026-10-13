<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

class Registry
{
    public function __construct(private CpfCnpj $cpfCnpj, private Hub $hub, private Apollo $apollo) {}
    public function get(string $provider): Supplier
    {
        return match ($provider) {
            'cpfcnpj' => $this->cpfCnpj, 'hub' => $this->hub, 'apollo' => $this->apollo,
            default => throw new \InvalidArgumentException('Unknown enrichment supplier.'),
        };
    }
}
