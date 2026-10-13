<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Persistence;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\Entities\User;
use Espo\Modules\FeatureLiveRecords\Scope;

/** Authoritative ownership only. This does not evaluate a viewer's record ACL. */
final class Routing
{
    public function __construct(private EntityManager $em, private TenantResolver $tenants,
        private UserTenantResolver $users, private array $recordTypes = Scope::RECORD_TYPES) {}

    public function snapshot(array $entities): array
    {
        $result = [];
        $seen = [];
        foreach ($entities as $entity) $this->collect($entity, $result, $seen);
        return $result;
    }

    private function collect(Entity $entity, array &$result, array &$seen): array
    {
        $type = $entity->getEntityType();
        if (!in_array($type, [...$this->recordTypes, ...Scope::DEPENDENCIES, ...Scope::SHARED, ...Scope::ACCESS], true)) {
            return [];
        }
        $dependent = in_array($type, Scope::DEPENDENCIES, true);
        $id = $entity->getId();
        if (!$id) return [];
        $key = "$type:$id";
        if (isset($seen[$key])) return $seen[$key];
        $seen[$key] = [];
        $current = $this->em->getEntityById($type, $id) ?? $entity;
        $tenantIds = [];
        if ($type === 'Tenant') $tenantIds = [$id];
        elseif ($type === 'Team') $tenantIds = $this->tenants->resolveAllFromTeamIds([$id]);
        elseif (in_array($type, ['Role', 'PortalRole'], true)) {
            // Shared role policy can affect multiple tenants. Send resets only,
            // never the role identity or another tenant's record identities.
            foreach ($this->em->getRDBRepository('ChatwootAccount')->select(['tenantId'])->find() as $account) {
                if ($account->get('tenantId')) $result[$account->get('tenantId')] ??= [];
            }
            return [];
        }
        elseif ($current->get('tenantId')) $tenantIds = [$current->get('tenantId')];
        elseif ($current instanceof User) $tenantIds = $this->users->resolveTenantIds($current);
        elseif ($current->hasRelation('teams')) {
            $ids = [];
            foreach ($this->em->getRDBRepository($type)->getRelation($current, 'teams')->find() as $team) $ids[] = $team->getId();
            $tenantIds = $this->tenants->resolveAllFromTeamIds($ids);
        }

        $references = [];
        foreach (['parent', 'subject', 'object', 'knowledgeRecord', 'record', 'target'] as $prefix) {
            if ($current->get($prefix . 'Type') && $current->get($prefix . 'Id')) {
                $references[] = [$current->get($prefix . 'Type'), $current->get($prefix . 'Id')];
            }
        }
        foreach (['initiative' => 'Initiative', 'document' => 'Document', 'sourceDocument' => 'Document',
            'chatwootAccount' => 'ChatwootAccount', 'opportunity' => 'Opportunity', 'account' => 'Account',
            'contact' => 'Contact', 'funnel' => 'Funnel', 'task' => 'Task', 'run' => 'PlaybookRun',
            'playbook' => 'Playbook', 'journey' => 'Journey', 'initiativeType' => 'InitiativeType'] as $prefix => $referenceType) {
            if ($current->get($prefix . 'Id')) $references[] = [$referenceType, $current->get($prefix . 'Id')];
        }
        if (in_array($type, ['EmailAddress', 'PhoneNumber'], true)) {
            $field = $type === 'EmailAddress' ? 'emailAddressId' : 'phoneNumberId';
            foreach ($this->em->getRDBRepository('Entity' . $type)
                ->where([$field => $id, 'entityType' => $this->recordTypes])->find() as $link) {
                $references[] = [$link->get('entityType'), $link->get('entityId')];
            }
        }
        $inherited = [];
        foreach ($references as [$referenceType, $referenceId]) {
            if ($dependent && !in_array($referenceType, [...$this->recordTypes, ...Scope::DEPENDENCIES], true)) continue;
            // Chatwoot's numeric accountId/userId fields are external IDs, not
            // CRM link IDs. Never resolve them as CRM records during a rebuild.
            if (!is_string($referenceId) || $referenceId === '') continue;
            $reference = $this->em->getEntityById($referenceType, $referenceId);
            if ($reference) $inherited = [...$inherited, ...$this->collect($reference, $result, $seen)];
        }
        if ($dependent && !$inherited) {
            return [];
        }
        // References can invalidate their own tenant, but never transplant this
        // record's identity across a declared tenant boundary.
        $tenantIds = array_values(array_unique($tenantIds ?: $inherited));
        $seen[$key] = $tenantIds;
        foreach ($tenantIds as $tenantId) $result[$tenantId][$key] = ['type' => $type, 'id' => $id];
        return $tenantIds;
    }
}
