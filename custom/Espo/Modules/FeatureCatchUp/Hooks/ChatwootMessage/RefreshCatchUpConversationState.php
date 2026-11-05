<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Hooks\ChatwootMessage;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCatchUp\Services\ConversationStates;
use Espo\ORM\Entity;

/** Keeps the Atendimento lane's per-conversation owner fact (last staff writer) current as messages sync in. */
class RefreshCatchUpConversationState
{
    public static int $order = 51;

    public function __construct(private ConversationStates $states, private Log $log) {}

    /** @param array<string, mixed> $options */
    public function afterSave(Entity $entity, array $options): void
    {
        try {
            $this->states->forMessage($entity);
        } catch (\Throwable $e) {
            // Em dia's deck must never break message sync.
            $this->log->warning('FeatureCatchUp: conversation state refresh failed for message {id}: {error}', [
                'id' => $entity->getId(), 'error' => $e->getMessage(),
            ]);
        }
    }
}
