<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Core\Exceptions\Conflict;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/** One billable person per tenant, across its Chatwoot accounts. */
class SeatRoster
{
    public function __construct(private EntityManager $entityManager, private PlanSubscription $subscription) {}

    public function read(string $tenantId, ?string $actorId = null): array
    {
        $disabled = [];
        foreach ($this->entityManager->getRDBRepository('SelfServiceSeat')->where(['tenantId' => $tenantId])->find() as $seat) {
            $disabled[(string) $seat->get('userId')] = $seat;
        }

        $rows = [];
        foreach ($this->entityManager->getRDBRepository('ChatwootAccountUserMembership')
            ->join('chatwootAccount')->join('chatwootUser')
            ->where(['chatwootAccount.tenantId' => $tenantId, 'chatwootAccount.deleted' => false,
                'chatwootUser.deleted' => false, 'OR' => [['isAI' => false], ['isAI' => null]]])->find() as $membership) {
            $account = $this->entityManager->getEntityById('ChatwootAccount', (string) $membership->get('chatwootAccountId'));
            $identity = $this->entityManager->getEntityById('ChatwootUser', (string) $membership->get('chatwootUserId'));
            if (!$account || !$identity || $account->get('conciergeUserId') === $identity->getId() ||
                PlanRecommendation::isInternalEmail((string) ($membership->get('email') ?: $identity->get('email')))) continue;

            $userId = (string) $identity->get('assignedUserId');
            $key = $userId ?: 'identity:' . $identity->getId();
            $seat = $disabled[$userId] ?? null;
            $user = $userId !== '' ? $this->entityManager->getEntityById('User', $userId) : null;
            $manageable = $user instanceof User && !$user->isAdmin() && !$user->isSystem();
            $rows[$key] ??= [
                'id' => $key, 'name' => (string) $membership->get('name'), 'email' => (string) $membership->get('email'),
                'disabled' => (bool) $seat?->get('disabled'),
                'pending' => $seat !== null && (bool) $seat->get('disabled') !== (bool) $seat->get('syncedDisabled'),
                'canDisable' => $manageable && $userId !== $actorId && !$seat?->get('disabled'),
                'isSelf' => $userId !== '' && $userId === $actorId,
            ];
        }

        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $enabled = count(array_filter($rows, static fn ($row) => !$row['disabled']));

        $paidSeats = $this->paidSeats($tenantId);
        $pending = (bool) array_filter($disabled, static fn ($seat) => (bool) $seat->get('disabled') !== (bool) $seat->get('syncedDisabled'));
        foreach ($rows as &$row) {
            $row['canEnable'] = $row['disabled'] && !$pending && $paidSeats > $enabled;
            $row['canDisable'] = $row['canDisable'] && !$pending;
        }
        unset($row);

        return ['users' => $rows, 'enabledUsers' => $enabled, 'minimumSeats' => max(1, $enabled),
            'pending' => $pending, 'paidSeats' => $paidSeats];
    }

    public function paidSeats(string $tenantId): int
    {
        $plan = $this->subscription->read($tenantId)['subscription'];
        return $plan && $plan['state'] === 'active' && ($plan['paidThrough'] ?? '') > gmdate('Y-m-d H:i:s')
            ? $plan['seats'] : 0;
    }

    public function assertCheckout(string $tenantId, int $quantity): void
    {
        $roster = $this->read($tenantId);
        if ($roster['pending']) throw new Conflict('SEAT_ACCESS_PENDING');
        if ($quantity < $roster['minimumSeats']) throw new Conflict('SEATS_BELOW_ENABLED_USERS');
    }
}
