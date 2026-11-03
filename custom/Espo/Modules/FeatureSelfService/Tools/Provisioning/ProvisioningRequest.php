<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureSelfService\Tools\Provisioning;

use InvalidArgumentException;

/**
 * What a signup asks for: a workspace owned by an existing CRM user.
 *
 * The dedupe key names the signup. Submitting it twice returns the same
 * provisioning; submitting it with different settings is a conflict.
 * Platforms, teams and credentials are chosen server-side, never here.
 */
final class ProvisioningRequest
{
    public readonly string $dedupeKey;
    public readonly string $ownerUserId;
    public readonly string $workspaceName;
    public readonly string $language;
    public readonly string $timeZone;

    public function __construct(
        string $dedupeKey,
        string $ownerUserId,
        string $workspaceName,
        string $language = 'pt_BR',
        string $timeZone = 'America/Sao_Paulo',
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/', $dedupeKey)) {
            throw new InvalidArgumentException('The dedupe key must be 1-128 lowercase letters, digits or ._:/-.');
        }

        // Espo ids: 17-character hex by default, or UUIDs in uuid id mode.
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,35}$/', $ownerUserId)) {
            throw new InvalidArgumentException('The owner must be a CRM user id.');
        }

        $workspaceName = trim((string) preg_replace('/\s+/u', ' ', $workspaceName));

        if ($workspaceName === '' || mb_strlen($workspaceName) > 100) {
            throw new InvalidArgumentException('The workspace name must have 1-100 characters.');
        }

        if (!preg_match('/^[a-z]{2}_[A-Z]{2}$/', $language)) {
            throw new InvalidArgumentException('The language must look like pt_BR.');
        }

        if (!in_array($timeZone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Unknown time zone.');
        }

        $this->dedupeKey = $dedupeKey;
        $this->ownerUserId = $ownerUserId;
        $this->workspaceName = $workspaceName;
        $this->language = $language;
        $this->timeZone = $timeZone;
    }

    /**
     * @return array{ownerUserId: string, workspaceName: string, language: string, timeZone: string}
     */
    public function toArray(): array
    {
        return [
            'ownerUserId' => $this->ownerUserId,
            'workspaceName' => $this->workspaceName,
            'language' => $this->language,
            'timeZone' => $this->timeZone,
        ];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode([$this->dedupeKey, $this->toArray()], JSON_THROW_ON_ERROR));
    }
}
