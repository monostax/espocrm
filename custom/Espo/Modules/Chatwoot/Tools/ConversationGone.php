<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * A conversation deleted in Chatwoot leaves the CRM when someone reads it and Chatwoot no longer has it, not through a
 * sweep: asking Chatwoot about every conversation each hour found 22 deletions in 30 days (2026-11) for about 500k
 * calls a day. Deletions are rare (admins only); until it is read, a deleted conversation just stays listed.
 */
class ConversationGone
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $chatwoot,
        private Log $log
    ) {}

    /**
     * After a call about the conversation failed: whether Chatwoot answers 404 for the conversation itself, in which
     * case the CRM copy is removed. A soft delete: the conversation sync restores it if it shows activity again. Any
     * other answer, an error included, keeps it.
     */
    public function check(Entity $conversation, Entity $account, Entity $platform): bool
    {
        $chatwootConversationId = (int) $conversation->get('chatwootConversationId');

        if (!$chatwootConversationId) {
            return false;
        }

        try {
            $found = $this->chatwoot->getConversation(
                (string) $platform->get('backendUrl'),
                (string) $account->get('apiKey'),
                (int) $account->get('chatwootAccountId'),
                $chatwootConversationId
            );
        } catch (\Throwable) {
            return false;
        }

        if ($found !== null) {
            return false;
        }

        $this->log->warning(
            "ConversationGone: removing conversation {$chatwootConversationId} of Chatwoot account " .
            "{$account->get('chatwootAccountId')} (entity {$conversation->getId()}), Chatwoot answered 404"
        );

        // skipChatwootDelete: the VerifyExistsInChatwoot hook must not delete it in Chatwoot, it is already gone.
        $this->entityManager->removeEntity($conversation, [
            'cascadeParent' => true,
            'skipChatwootDelete' => true,
        ]);

        return true;
    }
}
