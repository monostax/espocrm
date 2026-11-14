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
 * The verified number is the person's, not the workspace's (2026-11-13). Each person turns it on for themselves (Meta
 * wants the recipient's own opt-in): Settings › Notificações no WhatsApp gives a code that the person sends from their
 * WhatsApp, or the signup's WhatsApp check does it for the owner. One code turns it on in every workspace of theirs with
 * Em dia on (`spread`); a workspace they join later gets it the first time it is needed. "PARAR" or the app's "Parar
 * avisos" stops it everywhere; only a new code turns it back on.
 *
 * A row per person and workspace stays: it holds that workspace's morning card and hot-lead switches, its deck cards,
 * its delivery log and charges, and goes with the workspace on offboarding.
 */
class Subscriptions
{
    private const KINDS = ['card', 'digest', 'hot_lead', 'reminder', 'reply'];
    /** The person's number and its WhatsApp thread and window, the same in every workspace. */
    private const NUMBER_FIELDS = ['phone', 'verifiedAt', 'lastInboundAt', 'avisosContactId', 'avisosConversationId'];
    /** What a workspace added later starts with: the person's preferences where the number was verified. */
    private const PREFERENCE_FIELDS = ['morningEnabled', 'morningTime', 'hotLeadsEnabled', 'alertStart', 'alertEnd'];

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
        $available = $this->available($account);
        // A workspace the person joined after verifying their number shows it on already.
        if ($available && !$this->find($this->user->getId(), $account->getId())) {
            $from = $this->em->getRDBRepository('WhatsAppNotificationSubscription')
                ->where(['userId' => $this->user->getId(), 'status' => 'active'])->order('lastInboundAt', 'DESC')->findOne();
            if ($from && $this->workspaces->live($from)) $this->spread($from);
        }
        return $this->view($account, $available);
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

    /** POST /WhatsAppNotifications/stop: the person's number stops in every workspace. */
    public function stopMine(Request $request): array
    {
        ['account' => $account] = $this->workspace->account($request);
        $this->find($this->user->getId(), $account->getId()) ?? throw new NotFound();
        $active = $this->em->getRDBRepository('WhatsAppNotificationSubscription')
            ->where(['userId' => $this->user->getId(), 'status' => 'active'])->find();
        foreach ($active as $subscription) $this->stop($subscription, 'user');
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
     * A verified number: the person's subscription in that workspace, active with this number, and the same in their
     * other workspaces (`spread`). Another person's active subscription with the same number in the workspace where it
     * was verified stops (`moved`): one number, one person per workspace.
     */
    public function activate(string $userId, string $accountId, string $phone, string $source, ?int $contactId, ?int $conversationId): Entity
    {
        $account = $this->em->getEntityById('ChatwootAccount', $accountId) ?? throw new NotFound();
        $user = $this->em->getEntityById('User', $userId) ?? throw new NotFound();
        $subscription = $this->find($userId, $accountId) ?? $this->em->getNewEntity('WhatsAppNotificationSubscription');
        $now = gmdate('Y-m-d H:i:s');
        $subscription->set([
            ...$this->identity($user, $account),
            'phone' => $phone, 'status' => 'active', 'source' => $source, 'verifiedAt' => $now,
            'stoppedAt' => null, 'stopReason' => null, 'lastInboundAt' => $now,
            'avisosContactId' => $contactId, 'avisosConversationId' => $conversationId,
        ]);
        $this->em->saveEntity($subscription);
        $others = $this->em->getRDBRepository('WhatsAppNotificationSubscription')->where([
            'phone' => $phone, 'chatwootAccountId' => $accountId, 'status' => 'active', 'id!=' => $subscription->getId(),
        ])->find();
        foreach ($others as $other) $this->stop($other, 'moved');
        $this->spread($subscription, true);
        return $subscription;
    }

    /**
     * The person's other workspaces with Em dia on get this number, its window and thread. Without $renew (a workspace
     * joined later) only missing rows are added: a stopped one stays stopped. With $renew (a new verification) the rows
     * they had turn back on with this number, keeping each workspace's own switches. A workspace where another person
     * holds the number keeps it with them. Returns how many rows changed.
     */
    public function spread(Entity $from, bool $renew = false): int
    {
        $userId = (string) $from->get('userId');
        $phone = (string) $from->get('phone');
        if ($userId === '' || $phone === '' || $from->get('status') !== 'active') return 0;
        $user = $this->em->getEntityById('User', $userId);
        if (!$user) return 0;
        $changed = 0;
        foreach ($this->workspaces->accountsOf($userId) as $account) {
            if ($account->getId() === $from->get('chatwootAccountId') || !$this->available($account)) continue;
            $row = $this->find($userId, $account->getId());
            if ($row && (!$renew || ($row->get('status') === 'active' && $row->get('phone') === $phone))) continue;
            $taken = $this->em->getRDBRepository('WhatsAppNotificationSubscription')->where([
                'phone' => $phone, 'chatwootAccountId' => $account->getId(), 'status' => 'active', 'userId!=' => $userId,
            ])->findOne();
            if ($taken) continue;
            if (!$row) {
                $row = $this->em->getNewEntity('WhatsAppNotificationSubscription');
                $row->set(['source' => 'person', ...array_combine(self::PREFERENCE_FIELDS, array_map(fn (string $field) => $from->get($field), self::PREFERENCE_FIELDS))]);
            }
            $row->set([
                ...$this->identity($user, $account),
                ...array_combine(self::NUMBER_FIELDS, array_map(fn (string $field) => $from->get($field), self::NUMBER_FIELDS)),
                'status' => 'active', 'stoppedAt' => null, 'stopReason' => null,
            ]);
            Sessions::setEditing($row, null);
            $this->em->saveEntity($row);
            $changed++;
        }
        return $changed;
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
     * The number's live subscriptions, latest used first, with the workspaces its people joined since they verified it
     * (`spread`). One left behind by a removed workspace, or by a person no longer in it, is passed over, so it can't
     * block the number's other workspaces (offboarding removes them; this covers any other way a workspace goes).
     *
     * @return list<Entity>
     */
    public function activeForPhone(string $phone): array
    {
        $load = fn (): array => array_values(iterator_to_array($this->em->getRDBRepository('WhatsAppNotificationSubscription')
            ->where(['phone' => $phone, 'status' => 'active'])->order('lastInboundAt', 'DESC')->find()));
        $subscriptions = $load();
        $people = [];
        foreach ($subscriptions as $subscription) {
            if ($this->workspaces->live($subscription)) $people[(string) $subscription->get('userId')] ??= $subscription;
        }
        $changed = 0;
        foreach ($people as $from) $changed += $this->spread($from);
        if ($changed) $subscriptions = $load();
        return array_values(array_filter($subscriptions, fn (Entity $subscription) => $this->workspaces->live($subscription)));
    }

    /** @return array<string, mixed> who and where, the same on every row */
    private function identity(Entity $user, Entity $account): array
    {
        return [
            'userId' => $user->getId(), 'chatwootAccountId' => $account->getId(), 'tenantId' => $account->get('tenantId'),
            'accountNumber' => (int) $account->get('chatwootAccountId'),
            'name' => mb_substr(trim($user->get('name') . ' · ' . ($account->get('name') ?? '')), 0, 150),
        ];
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
