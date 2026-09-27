<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Chatwoot account settings own the opt-in. A missing flag, ambiguous mapping or failed lookup never enables it. */
class Rollout
{
    public function __construct(private EntityManager $em, private SelectBuilderFactory $select, private ChatwootApiClient $api) {}

    public function forAccount(Entity $account): array
    {
        $platform = $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));
        if (!$platform || !$platform->get('backendUrl') || !$platform->get('accessToken') || !$platform->get('frontendUrl')) throw new Forbidden();
        $remote = $this->api->getAccount($platform->get('backendUrl'), $platform->get('accessToken'), (int) $account->get('chatwootAccountId'));
        $settings = $remote['catch_up'] ?? [];
        if (($remote['status'] ?? '') !== 'active' || ($settings['enabled'] ?? false) !== true) throw new Forbidden('Catch Up is not enabled for this account.');
        $limit = $settings['dailyLimit'] ?? 20;
        if (empty($settings['instance'])) throw new Forbidden();
        return ['enabled' => true, 'instance' => rtrim($settings['instance'], '/'),
            'accountId' => (string) $account->get('chatwootAccountId'), 'dailyLimit' => is_int($limit) ? min(50, max(0, $limit)) : 20];
    }

    public function forTenant(string $tenantId): array
    {
        $query = $this->select->create()->from('ChatwootAccount')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['tenantId' => $tenantId])->limit(0, 2)->build();
        $accounts = iterator_to_array($this->em->getRDBRepository('ChatwootAccount')->clone($query)->find());
        if (count($accounts) !== 1) throw new Forbidden('Catch Up account is unavailable.');
        return $this->forAccount(reset($accounts));
    }

    public function forOpportunity(string $id): array
    {
        $record = $this->em->getEntityById('Opportunity', $id);
        if (!$record || !$record->get('tenantId')) throw new Forbidden();
        return $this->forTenant($record->get('tenantId'));
    }
}
