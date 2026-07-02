<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Classes\FieldProcessing\MetaConversionEvent;

use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaConversionEvent;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Read-through `opportunity` for MetaConversionEvent.
 *
 * WHY
 * ---
 * The stored `opportunityId` on a conversion row is a creation-time snapshot:
 * it is only populated when THIS row's ingest created the Opportunity. Later
 * rows on the same conversation (or Opportunities created/linked through other
 * paths) leave the column empty even though the conversation itself is linked
 * to an Opportunity via the `chatwootConversationOpportunity` many-to-many.
 *
 * This loader resolves the conversation's most recent linked Opportunity at
 * read time and overrides the displayed `opportunity` link, mirroring the
 * always-current read-through used for `conversationName`. When the
 * conversation has no linked Opportunity, the stored value (if any) is kept.
 *
 * The stored column is NOT removed or bypassed for writes — it remains the
 * attribution key used by CapiDispatcher::findCtwaConversionForOpportunity.
 * Only the presented value is overridden. The entity is UI read-only
 * (recordDefs readOnly), so the overridden value can never be saved back.
 *
 * Registered as BOTH read (detail) and list loader in
 * recordDefs/MetaConversionEvent.json. In list context the select may omit
 * `chatwootConversationId`; the loader re-fetches that single column by id
 * before resolving. SAFETY: never throws — one broken row must not break
 * list rendering.
 *
 * @implements Loader<Entity>
 */
class ConversationOpportunityLoader implements Loader
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        try {
            $conversationId = $this->resolveConversationId($entity);

            if ($conversationId === null) {
                return;
            }

            $opportunity = $this->entityManager
                ->getRDBRepository(Opportunity::ENTITY_TYPE)
                ->join('chatwootConversations')
                ->where(['chatwootConversations.id' => $conversationId])
                ->order('createdAt', 'desc')
                ->findOne();

            if (!$opportunity) {
                // Keep the stored (creation-time) link as fallback.
                return;
            }

            $entity->set('opportunityId', $opportunity->getId());
            $entity->set('opportunityName', $opportunity->get('name'));
        } catch (Throwable $e) {
            $this->log->warning(
                'MetaConversionEvent ConversationOpportunityLoader: failed for '
                . (string) $entity->getId() . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Get the row's chatwootConversationId, re-fetching the single column when
     * the list select did not include it.
     */
    private function resolveConversationId(Entity $entity): ?string
    {
        if ($entity->has('chatwootConversationId')) {
            $id = $entity->get('chatwootConversationId');

            return is_string($id) && $id !== '' ? $id : null;
        }

        $row = $this->entityManager
            ->getRDBRepository(MetaConversionEvent::ENTITY_TYPE)
            ->select(['chatwootConversationId'])
            ->where(['id' => $entity->getId()])
            ->findOne();

        if (!$row) {
            return null;
        }

        $id = $row->get('chatwootConversationId');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
