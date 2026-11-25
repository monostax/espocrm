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

use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Core\Exceptions\Error;

/** Chat's account API with the person's token. A failed call becomes a ChatError with Chat's status. */
class ChatClient
{
    public function __construct(
        private ChatwootApiClient $chatwoot,
    ) {}

    /**
     * @param array<string, scalar|list<scalar>> $query
     * @return array<string, mixed>|list<mixed>
     * @throws ChatError
     */
    public function get(ChatAccess $access, string $path, array $query = []): array
    {
        $suffix = $query ? '?' . preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($query)) : '';

        return $this->send($access, 'GET', $path . $suffix, null);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|list<mixed>
     * @throws ChatError
     */
    public function post(ChatAccess $access, string $path, array $body): array
    {
        return $this->send($access, 'POST', $path, $body);
    }

    /**
     * @param ?array<string, mixed> $body
     * @return array<string, mixed>|list<mixed>
     * @throws ChatError
     */
    private function send(ChatAccess $access, string $method, string $path, ?array $body): array
    {
        try {
            $response = $this->chatwoot->accountRequest($access->baseUrl, $access->token, $method, $access->root() . $path, $body);
        } catch (Error) {
            throw new ChatError(0, 'Chat did not answer.');
        }

        $code = (int) $response['code'];

        if ($code < 200 || $code >= 300) {
            $data = $response['body'] ?? [];
            $message = is_array($data) ? ($data['message'] ?? $data['error'] ?? null) : null;

            throw new ChatError($code, is_string($message) ? $message : "Chat answered $code.");
        }

        return is_array($response['body'] ?? null) ? $response['body'] : [];
    }
}
