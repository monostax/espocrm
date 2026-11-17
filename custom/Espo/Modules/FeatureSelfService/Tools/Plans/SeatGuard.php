<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Record\Hook\LinkHook;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Ordinary user/team management cannot restore an unpaid, disabled seat. */
class SeatGuard implements LinkHook
{
    public static int $order = -20;

    public function __construct(private EntityManager $entityManager, private TenantResolver $tenants) {}

    public function process(Entity $entity, string $link, Entity $foreignEntity): void
    {
        [$userId, $tenantIds] = match ([$entity->getEntityType(), $link]) {
            ['User', 'tenants'] => [$entity->getId(), [$foreignEntity->getId()]],
            ['User', 'teams'] => [$entity->getId(), $this->tenants->resolveAllFromTeamIds([$foreignEntity->getId()])],
            ['Tenant', 'users'] => [$foreignEntity->getId(), [$entity->getId()]],
            ['Team', 'users'] => [$foreignEntity->getId(), $this->tenants->resolveAllFromTeamIds([$entity->getId()])],
            default => [null, []],
        };
        if ($userId) $this->assertEnabled($userId, $tenantIds);
    }

    public function afterRelate(Entity $entity, array $options, array $params): void
    {
        if (!empty($options[SeatAccess::SAVE_OPTION])) return;
        $link = $params['relationName'] ?? '';
        $type = match ([$entity->getEntityType(), $link]) {
            ['User', 'tenants'] => 'Tenant', ['User', 'teams'] => 'Team',
            ['Tenant', 'users'], ['Team', 'users'] => 'User', default => null,
        };
        if (!$type || empty($params['foreignId'])) return;
        $foreign = $this->entityManager->getEntityById($type, $params['foreignId']);
        if (!$foreign) return;
        try {
            $this->process($entity, $link, $foreign);
        } catch (Conflict $e) {
            // ORM relations have only afterRelate. Undo before raising, including
            // direct integration writes that do not open an outer transaction.
            $this->entityManager->getRDBRepository($entity->getEntityType())->getRelation($entity, $link)
                ->unrelateById($foreign->getId(), [SeatAccess::SAVE_OPTION => true]);
            throw $e;
        }
    }

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!empty($options[SeatAccess::SAVE_OPTION])) return;
        if ($entity->getEntityType() === 'User') {
            if ($entity->isAttributeChanged('isActive') && $entity->get('isActive')) {
                foreach ($this->disabledSeats((string) $entity->getId()) as $seat) {
                    if (((array) $seat->get('accessSnapshot'))['deactivateUser'] ?? false) throw new Conflict('PAID_SEAT_REQUIRED');
                }
            }
            $teams = $entity->isAttributeChanged('teamsIds') ? (array) $entity->get('teamsIds') : [];
            if ($entity->isAttributeChanged('defaultTeamId') && $entity->get('defaultTeamId')) $teams[] = $entity->get('defaultTeamId');
            $tenants = $entity->isAttributeChanged('tenantsIds') ? (array) $entity->get('tenantsIds') : [];
            $this->assertEnabled((string) $entity->getId(), array_merge($tenants, $this->tenants->resolveAllFromTeamIds($teams)));
        }
        if (in_array($entity->getEntityType(), ['Tenant', 'Team'], true) && $entity->isAttributeChanged('usersIds')) {
            $tenantIds = $entity->getEntityType() === 'Tenant' ? [$entity->getId()] : $this->tenants->resolveAllFromTeamIds([$entity->getId()]);
            foreach ((array) $entity->get('usersIds') as $id) $this->assertEnabled($id, $tenantIds);
        }
        if ($entity->getEntityType() === 'ChatwootUser' && $entity->isAttributeChanged('assignedUserId')) {
            if (!$entity->isNew()) $this->assertNoDisabledSeats((string) $entity->getFetched('assignedUserId'));
            $this->assertNoDisabledSeats((string) $entity->get('assignedUserId'));
        }
        if ($entity->isNew() && $entity->getEntityType() === 'ChatwootAccountUserMembership') {
            $this->assertMembershipEnabled($entity);
        }
        if (!$entity->isNew() && $entity->getEntityType() === 'ChatwootAccountUserMembership' &&
            ($entity->isAttributeChanged('chatwootUserId') || $entity->isAttributeChanged('chatwootAccountId') || $entity->isAttributeChanged('isAI'))) {
            $this->assertMembershipEnabled($entity, true);
            $this->assertMembershipEnabled($entity);
        }
    }

    public function beforeRemove(Entity $entity, array $options): void
    {
        if (!empty($options['tenantOffboarding'])) return;
        match ($entity->getEntityType()) {
            'User' => $this->assertNoDisabledSeats((string) $entity->getId()),
            'ChatwootUser' => $this->assertNoDisabledSeats((string) $entity->get('assignedUserId')),
            'ChatwootAccountUserMembership' => $this->assertMembershipEnabled($entity),
            default => null,
        };
    }

    private function assertMembershipEnabled(Entity $membership, bool $fetched = false): void
    {
        $user = $this->entityManager->getEntityById('ChatwootUser', (string) ($fetched ? $membership->getFetched('chatwootUserId') : $membership->get('chatwootUserId')));
        $account = $this->entityManager->getEntityById('ChatwootAccount', (string) ($fetched ? $membership->getFetched('chatwootAccountId') : $membership->get('chatwootAccountId')));
        if ($user && $account) $this->assertEnabled((string) $user->get('assignedUserId'), [(string) $account->get('tenantId')]);
    }

    private function assertNoDisabledSeats(string $userId): void
    {
        foreach ($this->disabledSeats($userId) as $seat) throw new Conflict('PAID_SEAT_REQUIRED');
    }

    private function assertEnabled(string $userId, array $tenantIds): void
    {
        if (!$tenantIds) return;
        foreach ($this->disabledSeats($userId) as $seat) {
            if (in_array($seat->get('tenantId'), $tenantIds, true)) throw new Conflict('PAID_SEAT_REQUIRED');
        }
    }

    private function disabledSeats(string $userId): iterable
    {
        if ($userId === '') return [];
        return $this->entityManager->getRDBRepository('SelfServiceSeat')->where(['userId' => $userId, 'disabled' => true])->find();
    }
}
