<?php

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class IntegrationUserNameResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
    ) {}

    public function resolve(?Entity $account): string
    {
        $locale = null;
        $tenantId = $account?->get('tenantId');

        if ($tenantId) {
            $locale = $this->entityManager->getEntityById('Tenant', $tenantId)?->get('language');
        }

        if (!is_string($locale) || $locale === '') {
            $locale = (string) ($this->config->get('language') ?? 'en_US');
        }

        return str_starts_with(strtolower(str_replace('-', '_', $locale)), 'pt')
            ? 'Monostax Integração'
            : 'Monostax Integration';
    }
}
