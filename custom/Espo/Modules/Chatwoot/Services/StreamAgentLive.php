<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Log;
use Espo\Entities\Note;
use Espo\ORM\EntityManager;
use GuzzleHttp\Client;

/** CRM-authorized, reply-scoped ActionCable delivery. Never an account broadcast. */
class StreamAgentLive
{
    public function __construct(private EntityManager $em, private Log $log) {}

    /** Called only after the viewer's current Note read ACL has been checked. */
    public function ticket(Note $reply, int $accountId, int $userId, string $pubsubToken): object
    {
        return $this->request($reply, 'stream_ticket', [
            'user_id' => $userId, 'pubsub_token' => $pubsubToken,
        ], $accountId);
    }

    public function publish(?Note $reply): void
    {
        if (!$reply) return;
        try {
            $progress = $reply->getData()->opportunityStreamAgent;
            $this->request($reply, 'stream_update', ['snapshot' => $progress,
                'post' => $progress->status === 'completed' ? $reply->getPost() : null]);
        } catch (\Throwable $error) {
            // The snapshot is already saved. Reconnect/fallback reads recover it.
            $this->log->warning('Stream preview broadcast unavailable for {id}: {error}', [
                'id' => $reply->getId(), 'error' => $error::class,
            ]);
        }
    }

    private function request(Note $reply, string $action, array $body, ?int $expectedAccountId = null): object
    {
        $progress = $reply->getData()->opportunityStreamAgent ?? null;
        $membership = $progress ? $this->em->getEntityById('ChatwootAccountUserMembership', $progress->aiAgentMembershipId) : null;
        $accountId = $membership?->get('chatwootAccountId');
        $account = $accountId ? $this->em->getEntityById('ChatwootAccount', $accountId) : null;
        $platformId = $account?->get('platformId');
        $platform = $platformId ? $this->em->getEntityById('ChatwootPlatform', $platformId) : null;
        $chatAccountId = (int) $account?->get('chatwootAccountId');
        $url = $platform?->get('backendUrl');
        $key = $account?->get('apiKey');
        if (!$url || !$key || !$chatAccountId || ($expectedAccountId !== null && $expectedAccountId !== $chatAccountId)) {
            throw new Forbidden('Reply streaming is unavailable for this account.');
        }
        $response = (new Client())->post(rtrim($url, '/') . "/api/v1/accounts/$chatAccountId/opportunity_events/$action", [
            'json' => ['note_id' => $reply->getId(), ...$body],
            'headers' => ['api_access_token' => $key],
            'connect_timeout' => 0.3, 'timeout' => 1.0, 'allow_redirects' => false,
        ]);
        $json = (string) $response->getBody();
        return $json === '' ? (object) [] : json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }
}
