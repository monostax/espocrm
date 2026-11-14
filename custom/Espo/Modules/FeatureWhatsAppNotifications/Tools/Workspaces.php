<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Error;
use Espo\Entities\User;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** The workspace a subscription belongs to: its tenant, Chatwoot account, time zone, language and app links. */
class Workspaces
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];
    /** @var array<string, array<string, true>> tenant ids per person */
    private array $tenantsOf = [];

    public function __construct(private EntityManager $em, private UserTenantResolver $tenants) {}

    /**
     * The person's workspaces: the Chatwoot accounts of the tenants they belong to (tenantUser or teams, the same check
     * as the app's workspace switcher; an instance admin gets no more). An inactive user has none.
     *
     * @return list<Entity>
     */
    public function accountsOf(string $userId): array
    {
        $tenantIds = array_keys($this->tenantIdsOf($userId));
        if (!$tenantIds) return [];
        return array_values(iterator_to_array($this->em->getRDBRepository('ChatwootAccount')->where(['tenantId' => $tenantIds])->find()));
    }

    /**
     * Whether the subscription still counts: its workspace exists and its person is still an active member of it.
     * Someone removed from a workspace gets nothing more from it.
     */
    public function live(Entity $subscription): bool
    {
        try {
            $tenantId = $this->of($subscription)['tenant']->getId();
        } catch (Error) {
            return false;
        }
        return isset($this->tenantIdsOf((string) $subscription->get('userId'))[$tenantId]);
    }

    /** @return array<string, true> */
    private function tenantIdsOf(string $userId): array
    {
        if (isset($this->tenantsOf[$userId])) return $this->tenantsOf[$userId];
        $user = $userId !== '' ? $this->em->getEntityById('User', $userId) : null;
        return $this->tenantsOf[$userId] = $user instanceof User && $user->isActive() ? $this->tenants->resolveTenantIdSet($user) : [];
    }

    /** Whether the subscription's Chatwoot account and tenant still exist (a removed workspace leaves neither). */
    public function exists(Entity $subscription): bool
    {
        try {
            $this->of($subscription);
            return true;
        } catch (Error) {
            return false;
        }
    }

    /**
     * @return array{tenant: Entity, account: Entity, platform: ?Entity, accountNumber: int, timeZone: string, language: string,
     *     name: string, frozen: bool}
     */
    public function of(Entity $subscription): array
    {
        $accountId = (string) $subscription->get('chatwootAccountId');
        if (isset($this->cache[$accountId])) return $this->cache[$accountId];
        $account = $this->em->getEntityById('ChatwootAccount', $accountId);
        $tenant = $account?->get('tenantId') ? $this->em->getEntityById('Tenant', (string) $account->get('tenantId')) : null;
        if (!$account || !$tenant) throw new Error('WhatsApp notifications: the subscription\'s workspace is gone.');
        $platform = $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));
        return $this->cache[$accountId] = [
            'tenant' => $tenant, 'account' => $account, 'platform' => $platform,
            'accountNumber' => (int) $account->get('chatwootAccountId'),
            'timeZone' => self::zone((string) ($tenant->get('timeZone') ?: 'America/Sao_Paulo')),
            'language' => Copy::language((string) $tenant->get('language')),
            'name' => (string) ($tenant->get('name') ?: $account->get('name') ?: ''),
            'frozen' => WorkspaceAccess::isFrozen($tenant),
        ];
    }

    /** The conversation in the app; a logged-out tap goes through the normal login. */
    public function link(Entity $subscription, int $conversationId): string
    {
        $workspace = $this->of($subscription);
        $base = rtrim((string) ($workspace['platform']?->get('frontendUrl') ?: 'https://chat.app.monostax.ai'), '/');
        return "{$base}/app/accounts/{$workspace['accountNumber']}/conversations/{$conversationId}";
    }

    /** Em dia in the app. */
    public function deckLink(Entity $subscription): string
    {
        $workspace = $this->of($subscription);
        $base = rtrim((string) ($workspace['platform']?->get('frontendUrl') ?: 'https://chat.app.monostax.ai'), '/');
        return "{$base}/app/accounts/{$workspace['accountNumber']}/catch-up";
    }

    /** Em dia opened on this card: Chatwoot's CatchUpView reads `?card=<lane>:<id>` and shows that card first. */
    public function cardLink(Entity $subscription, Entity $card): string
    {
        return $this->deckLink($subscription) . '?card=' . $card->get('lane') . ':' . $card->get('subjectId');
    }

    /** The person's AI chat in the app. */
    public function sessionLink(Entity $subscription, string $sessionId): string
    {
        $workspace = $this->of($subscription);
        $base = rtrim((string) ($workspace['platform']?->get('frontendUrl') ?: 'https://chat.app.monostax.ai'), '/');
        return "{$base}/app/accounts/{$workspace['accountNumber']}/ai-sessions/{$sessionId}";
    }

    public function now(Entity $subscription): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($this->of($subscription)['timeZone']));
    }

    private static function zone(string $zone): string
    {
        try {
            new DateTimeZone($zone);
            return $zone;
        } catch (\Exception) {
            return 'America/Sao_Paulo';
        }
    }
}
