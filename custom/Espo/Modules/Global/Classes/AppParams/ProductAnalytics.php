<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\AppParams;

use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Tools\App\AppParam;

/**
 * PostHog settings for product analytics, or null while `posthogProjectToken` is unset.
 *
 * Chatwoot, the main application, identifies people as `user-<Chatwoot user ID>`. The CRM
 * resolves the same ID through the user's ChatwootUser (oldest first, as ChatwootAccountId
 * does), so both report one person. Users without one, such as instance admins, report as
 * `crm-user-<CRM user ID>`.
 */
class ProductAnalytics implements AppParam
{
    private const DEFAULT_HOST = 'https://us.i.posthog.com';

    public function __construct(
        private User $user,
        private Config $config,
        private EntityManager $entityManager,
    ) {}

    /**
     * @return ?array{token: string, host: string, distinctId: string}
     */
    public function get(): ?array
    {
        $token = $this->config->get('posthogProjectToken');

        if (!is_string($token) || $token === '' || !($this->user->isRegular() || $this->user->isAdmin())) {
            return null;
        }

        $chatwootUser = $this->entityManager
            ->getRDBRepository('ChatwootUser')
            ->select(['id', 'chatwootUserId'])
            ->where(['assignedUserId' => $this->user->getId()])
            ->order('createdAt', 'ASC')
            ->findOne();

        $chatwootUserId = $chatwootUser?->get('chatwootUserId');
        $host = $this->config->get('posthogHost');

        return [
            'token' => $token,
            'host' => is_string($host) && $host !== '' ? $host : self::DEFAULT_HOST,
            'distinctId' => $chatwootUserId ? 'user-' . $chatwootUserId : 'crm-user-' . $this->user->getId(),
        ];
    }
}
