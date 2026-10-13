<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing\Asaas;

use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use InvalidArgumentException;

/** One immutable logical provider account/environment; credential rotation does not change identity. */
final readonly class Connection
{
    public function __construct(
        public string $id,
        public string $environment,
        #[\SensitiveParameter] private string $key,
        public bool $creationEnabled = false,
    ) {
        GrantInput::identity($id);
        if (!in_array($environment, ['sandbox', 'production'], true) || $key === '' || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new InvalidArgumentException('Invalid Asaas connection configuration.');
        }
    }

    public function baseUrl(): string
    {
        return $this->environment === 'sandbox' ? 'https://api-sandbox.asaas.com/v3' : 'https://api.asaas.com/v3';
    }

    public function headers(): array
    {
        return ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: Monostax-CRM-Billing/1', 'access_token: ' . $this->key];
    }

    public function __debugInfo(): array { return ['id' => $this->id, 'environment' => $this->environment]; }
}
