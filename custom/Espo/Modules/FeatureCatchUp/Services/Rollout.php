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
    /**
     * Every conversation is analysed when it settles (2026-11-11): sized for ~350 settles a day (Grupo Mousa) with room.
     * Keep in step with Chatwoot's CatchUpConfiguration.
     */
    public const DEFAULT_DAILY_LIMIT = 1000;
    public const MAX_DAILY_LIMIT = 2000;

    /** @var array<string, array> Opt-ins already confirmed in this request: a deck batch reads one account up to ten times. */
    private array $policies = [];

    public function __construct(private EntityManager $em, private SelectBuilderFactory $select, private ChatwootApiClient $api) {}

    public function forAccount(Entity $account): array
    {
        return $this->policies[(string) $account->getId()] ??= $this->fetch($account);
    }

    private function fetch(Entity $account): array
    {
        $platform = $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));
        if (!$platform || !$platform->get('backendUrl') || !$platform->get('accessToken') || !$platform->get('frontendUrl')) throw new Forbidden();
        $remote = $this->api->getAccount($platform->get('backendUrl'), $platform->get('accessToken'), (int) $account->get('chatwootAccountId'));
        $settings = $remote['catch_up'] ?? [];
        if (($remote['status'] ?? '') !== 'active' || ($settings['enabled'] ?? false) !== true) throw new Forbidden('Catch Up is not enabled for this account.');
        $limit = $settings['dailyLimit'] ?? self::DEFAULT_DAILY_LIMIT;
        if (empty($settings['instance'])) throw new Forbidden();
        return ['enabled' => true, 'instance' => rtrim($settings['instance'], '/'), 'accountId' => (string) $account->get('chatwootAccountId'),
            'dailyLimit' => is_int($limit) ? min(self::MAX_DAILY_LIMIT, max(0, $limit)) : self::DEFAULT_DAILY_LIMIT];
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
