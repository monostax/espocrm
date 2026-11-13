<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\Rollout;
use Espo\Modules\FeatureCatchUp\Services\Workspace;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * One subscription per person and workspace. Each person turns it on for themselves (Meta wants the recipient's own
 * opt-in): Settings › Notificações no WhatsApp gives a code that the person sends from their WhatsApp, or the signup's
 * WhatsApp check does it for the owner. "PARAR" or the app's "Parar avisos" stops it; only a new code turns it back on.
 */
class Subscriptions
{
    private const KINDS = ['card', 'digest', 'hot_lead', 'reminder', 'reply'];

    public function __construct(
        private EntityManager $em,
        private User $user,
        private Settings $settings,
        private Workspace $workspace,
        private Rollout $rollout,
        private PhoneCodes $codes,
        private Avisos $avisos,
        private Workspaces $workspaces,
    ) {}

    /** GET /WhatsAppNotifications/me */
    public function me(Request $request): array
    {
        ['account' => $account] = $this->workspace->account($request);
        return $this->view($account, $this->available($account));
    }

    /** POST /WhatsAppNotifications/code: a new code to send from WhatsApp (turning it on, a new number, or back on). */
    public function code(Request $request): array
    {
        ['account' => $account] = $this->workspace->account($request);
        if (!$this->available($account)) throw new Forbidden('Em dia is not on for this workspace.');
        $this->codes->issue(PhoneCodes::SUBSCRIPTION, ['userId' => $this->user->getId(), 'chatwootAccountId' => $account->getId()]);
        return $this->view($account, true);
    }

    /** PUT /WhatsAppNotifications/me: the morning card, hot leads and alert hours. */
    public function update(Request $request): array
    {
        ['account' => $account] = $this->workspace->account($request);
        $subscription = $this->find($this->user->getId(), $account->getId()) ?? throw new NotFound();
        $body = $request->getParsedBody();
        $time = fn (mixed $value) => is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $value) ? $value : throw new BadRequest('Invalid time.');
        if (isset($body->morning)) {
            $subscription->set('morningEnabled', (bool) ($body->morning->enabled ?? false));
            if (isset($body->morning->time)) {
                $morning = $time($body->morning->time);
                // A new time today still sends today's card if it hasn't gone out.
                if ($morning !== $subscription->get('morningTime')) $subscription->set('morningTime', $morning);
            }
        }
        if (isset($body->hotLeads)) $subscription->set('hotLeadsEnabled', (bool) ($body->hotLeads->enabled ?? false));
        if (isset($body->alertHours)) {
            $subscription->set(['alertStart' => $time($body->alertHours->start ?? null), 'alertEnd' => $time($body->alertHours->end ?? null)]);
        }
        $this->em->saveEntity($subscription);
        return $this->view($account, $this->available($account));
    }

    /** POST /WhatsAppNotifications/stop */
    public function stopMine(Request $request): array
    {
        ['account' => $account] = $this->workspace->account($request);
        $subscription = $this->find($this->user->getId(), $account->getId()) ?? throw new NotFound();
        $this->stop($subscription, 'user');
        return $this->view($account, $this->available($account));
    }

    /** The current person's active subscription in this workspace (POST /WhatsAppNotifications/test). */
    public function mine(Request $request): Entity
    {
        ['account' => $account] = $this->workspace->account($request);
        $subscription = $this->find($this->user->getId(), $account->getId());
        if (!$subscription || $subscription->get('status') !== 'active') throw new NotFound();
        return $subscription;
    }

    /**
     * A verified number: the person's subscription in that workspace, active with this number. Another person's active
     * subscription with the same number in the same workspace stops (`moved`): one number, one person per workspace.
     */
    public function activate(string $userId, string $accountId, string $phone, string $source, ?int $contactId, ?int $conversationId): Entity
    {
        $account = $this->em->getEntityById('ChatwootAccount', $accountId) ?? throw new NotFound();
        $user = $this->em->getEntityById('User', $userId) ?? throw new NotFound();
        $subscription = $this->find($userId, $accountId) ?? $this->em->getNewEntity('WhatsAppNotificationSubscription');
        $now = gmdate('Y-m-d H:i:s');
        $subscription->set([
            'userId' => $userId, 'chatwootAccountId' => $accountId, 'tenantId' => $account->get('tenantId'),
            'accountNumber' => (int) $account->get('chatwootAccountId'),
            'name' => mb_substr(trim($user->get('name') . ' · ' . ($account->get('name') ?? '')), 0, 150),
            'phone' => $phone, 'status' => 'active', 'source' => $source, 'verifiedAt' => $now,
            'stoppedAt' => null, 'stopReason' => null, 'lastInboundAt' => $now,
            'avisosContactId' => $contactId, 'avisosConversationId' => $conversationId,
        ]);
        $this->em->saveEntity($subscription);
        $others = $this->em->getRDBRepository('WhatsAppNotificationSubscription')->where([
            'phone' => $phone, 'chatwootAccountId' => $accountId, 'status' => 'active', 'id!=' => $subscription->getId(),
        ])->find();
        foreach ($others as $other) $this->stop($other, 'moved');
        return $subscription;
    }

    /** "PARAR" or "🔕 Parar avisos" from the number: every active subscription of it stops, with one confirmation. */
    public function stopPhone(string $phone, string $reason): void
    {
        $subscriptions = iterator_to_array($this->em->getRDBRepository('WhatsAppNotificationSubscription')
            ->where(['phone' => $phone, 'status' => 'active'])->find());
        foreach ($subscriptions as $subscription) $this->stop($subscription, $reason);
        if ($subscriptions) {
            $first = reset($subscriptions);
            $this->avisos->text($first, 'reply', Copy::t($this->workspaces->of($first)['language'], 'stopped'));
        }
    }

    public function stop(Entity $subscription, string $reason): void
    {
        $subscription->set(['status' => 'stopped', 'stoppedAt' => gmdate('Y-m-d H:i:s'), 'stopReason' => $reason]);
        Sessions::setEditing($subscription, null);
        $this->em->saveEntity($subscription);
    }

    public function find(string $userId, string $accountId): ?Entity
    {
        return $this->em->getRDBRepository('WhatsAppNotificationSubscription')
            ->where(['userId' => $userId, 'chatwootAccountId' => $accountId])->findOne();
    }

    /**
     * The number's live subscriptions, latest used first. One left behind by a removed workspace is passed over, so it
     * can't block the number's other workspaces (offboarding removes them; this covers any other way a workspace goes).
     *
     * @return list<Entity>
     */
    public function activeForPhone(string $phone): array
    {
        $subscriptions = iterator_to_array($this->em->getRDBRepository('WhatsAppNotificationSubscription')
            ->where(['phone' => $phone, 'status' => 'active'])->order('lastInboundAt', 'DESC')->find());
        return array_values(array_filter($subscriptions, fn (Entity $subscription) => $this->workspaces->exists($subscription)));
    }

    private function available(Entity $account): bool
    {
        if (!$this->settings->enabled()) return false;
        try {
            $this->rollout->forAccount($account);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function view(Entity $account, bool $available): array
    {
        $subscription = $this->find($this->user->getId(), $account->getId());
        $tenant = $account->get('tenantId') ? $this->em->getEntityById('Tenant', (string) $account->get('tenantId')) : null;
        $at = fn (?string $value) => $value ? gmdate(DATE_ATOM, (int) strtotime($value . ' UTC')) : null;
        $last = $subscription ? $this->em->getRDBRepository('WhatsAppNotification')
            ->where(['subscriptionId' => $subscription->getId(), 'direction' => 'out'])->order('createdAt', 'DESC')->findOne() : null;
        return [
            'available' => $available,
            'number' => $this->settings->displayNumber(),
            'timeZone' => (string) ($tenant?->get('timeZone') ?: 'America/Sao_Paulo'),
            'subscription' => $subscription ? [
                'status' => $subscription->get('status'), 'phone' => $subscription->get('phone'),
                'verifiedAt' => $at($subscription->get('verifiedAt')),
                'morning' => ['enabled' => (bool) $subscription->get('morningEnabled'), 'time' => (string) $subscription->get('morningTime')],
                'hotLeads' => ['enabled' => (bool) $subscription->get('hotLeadsEnabled')],
                'alertHours' => ['start' => (string) $subscription->get('alertStart'), 'end' => (string) $subscription->get('alertEnd')],
                'stoppedAt' => $at($subscription->get('stoppedAt')), 'stopReason' => $subscription->get('stopReason'),
            ] : null,
            'pending' => $available ? $this->codes->pending(PhoneCodes::SUBSCRIPTION, ['userId' => $this->user->getId(), 'chatwootAccountId' => $account->getId()]) : null,
            'lastDelivery' => $last ? [
                'kind' => in_array($last->get('kind'), self::KINDS, true) ? $last->get('kind') : 'reply',
                'status' => $last->get('status') === 'queued' ? 'sent' : $last->get('status'),
                'error' => $last->get('error'), 'at' => $at($last->get('createdAt')),
            ] : null,
        ];
    }
}
