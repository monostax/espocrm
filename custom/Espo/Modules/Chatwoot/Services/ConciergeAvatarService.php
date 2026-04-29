<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;

/**
 * Uploads the Monostax CRM branding icon (`client/img/favicon-512x512.png`)
 * as the avatar of a freshly-created concierge user on Chatwoot.
 *
 * Rationale: the concierge is a system user that authors automation messages
 * on behalf of the tenant. Without an avatar it shows up as a generic
 * Chatwoot placeholder, which is indistinguishable from a misconfigured
 * agent. Branding it with the product icon makes the conversation UI
 * instantly recognizable in both Chatwoot's agent app and in EspoCRM's
 * conversation view (which consumes the Chatwoot thumbnail via
 * `ChatwootAccountUserMembership.avatarUrl`).
 *
 * The upload uses the user's own `api_access_token` against
 * `PUT /api/v1/profile` with a multipart body, because:
 *   - Platform API `POST /platform/api/v1/users` does NOT accept avatar params.
 *   - `avatar_url` (remote URL) would require the Chatwoot host to be able to
 *     reach the CRM's `siteUrl`, which we can't guarantee (and often can't in
 *     dev/k8s split-network setups). File upload works regardless.
 *
 * All operations are best-effort: if the upload fails for any reason
 * (unreachable platform, misconfigured URL, missing file, permission issue),
 * we log and return null so the caller can still proceed. A missing avatar
 * is a cosmetic regression, never a deal-breaker for concierge creation.
 */
class ConciergeAvatarService
{
    /**
     * Relative path (from the EspoCRM installation root) of the branded
     * Monostax favicon used as the concierge avatar.
     *
     * Kept as a relative path so the `$appRoot . '/' . self::FAVICON_RELATIVE_PATH`
     * idiom works regardless of whether the code is running under Apache
     * (cwd = /var/www/html) or under a CLI rebuild (cwd = wherever the
     * caller happened to be).
     */
    private const FAVICON_RELATIVE_PATH = 'client/img/favicon-512x512.png';

    public function __construct(
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Upload the favicon as the concierge user's avatar on Chatwoot.
     *
     * @param string $platformUrl     Base URL of the Chatwoot platform
     * @param string $userAccessToken The concierge user's personal `api_access_token`
     *                                (as returned by Platform API `POST /platform/api/v1/users`
     *                                in the `access_token` field)
     * @param int|null $chatwootUserId Optional — logged for traceability only
     * @return string|null             The hosted avatar URL on Chatwoot on success;
     *                                 null if the favicon file can't be found or the
     *                                 upload failed (reason logged).
     */
    public function uploadForConcierge(
        string $platformUrl,
        string $userAccessToken,
        ?int $chatwootUserId = null
    ): ?string {
        if ($userAccessToken === '') {
            $this->log->warning(
                'ConciergeAvatarService: Skipping avatar upload — no user access token provided' .
                ($chatwootUserId ? " (user ID: {$chatwootUserId})" : '')
            );
            return null;
        }

        $faviconPath = $this->resolveFaviconPath();

        if ($faviconPath === null) {
            $this->log->warning(
                'ConciergeAvatarService: Skipping avatar upload — could not locate ' .
                self::FAVICON_RELATIVE_PATH . ' under any known app root'
            );
            return null;
        }

        try {
            $response = $this->apiClient->updateUserAvatar(
                $platformUrl,
                $userAccessToken,
                $faviconPath
            );

            $avatarUrl = $this->extractAvatarUrl($response);

            $this->log->info(
                'ConciergeAvatarService: Uploaded favicon as concierge avatar' .
                ($chatwootUserId ? " (user ID: {$chatwootUserId})" : '') .
                ($avatarUrl ? " -> {$avatarUrl}" : '')
            );

            return $avatarUrl;
        } catch (\Throwable $e) {
            // Best-effort: never let an avatar upload failure cascade into
            // the concierge creation transaction. The ChatwootAccountUserMembership
            // sync jobs will eventually reconcile the avatar if it's set later.
            $this->log->warning(
                'ConciergeAvatarService: Failed to upload concierge avatar' .
                ($chatwootUserId ? " (user ID: {$chatwootUserId})" : '') .
                ' - ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Locate the favicon on disk. The EspoCRM install root is normally the
     * process cwd (under Apache: /var/www/html; under rebuild CLI: the same).
     * We also fall back to `dirname(__DIR__, 5)` which resolves to the install
     * root given the stable custom-module path
     * `custom/Espo/Modules/Chatwoot/Services/ConciergeAvatarService.php`.
     */
    private function resolveFaviconPath(): ?string
    {
        $candidates = [];

        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $candidates[] = $cwd . DIRECTORY_SEPARATOR . self::FAVICON_RELATIVE_PATH;
        }

        // custom/Espo/Modules/Chatwoot/Services -> install root is 5 levels up.
        $candidates[] = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . self::FAVICON_RELATIVE_PATH;

        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * Pull the hosted avatar URL out of the Chatwoot profile response.
     * Chatwoot typically returns it as `avatar_url` at the top level, but
     * we also accept a nested `data.avatar_url` shape defensively.
     *
     * @param array<string, mixed> $response
     */
    private function extractAvatarUrl(array $response): ?string
    {
        if (isset($response['avatar_url']) && is_string($response['avatar_url']) && $response['avatar_url'] !== '') {
            return $response['avatar_url'];
        }

        if (
            isset($response['data']) &&
            is_array($response['data']) &&
            isset($response['data']['avatar_url']) &&
            is_string($response['data']['avatar_url']) &&
            $response['data']['avatar_url'] !== ''
        ) {
            return $response['data']['avatar_url'];
        }

        return null;
    }
}
