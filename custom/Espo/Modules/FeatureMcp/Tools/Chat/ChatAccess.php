<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Chat;

use SensitiveParameter;

/**
 * A verified way to act in one Chat workspace as the person. Holds their Chat token: never serialize it, return it
 * from a tool or log it.
 */
readonly class ChatAccess
{
    public function __construct(
        public int $accountId,
        public string $workspace,
        public int $userId,
        public string $baseUrl,
        #[SensitiveParameter] public string $token,
    ) {}

    public function root(): string
    {
        return '/api/v1/accounts/' . $this->accountId;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['accountId' => $this->accountId, 'workspace' => $this->workspace, 'userId' => $this->userId];
    }
}
