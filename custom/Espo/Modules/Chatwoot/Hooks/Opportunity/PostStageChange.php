<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Opportunity;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\CustomField\AiWriters;
use Espo\Modules\Global\Tools\CustomField\MetaProvider;
use Espo\Modules\Global\Tools\Opportunity\StageRequirements;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Every stage movement of an existing opportunity leaves one event on its stream: who moved it, from which stage to
 * which. Whoever can read the opportunity's stream can read it. The mover is the acting user: a person, the AI agent's
 * CRM user or the AI watcher's.
 *
 * When the mover is an AI agent, the event also names the custom fields it filled in on the way and the required ones
 * it left empty, which a person completes (user decision, 2026-11-24). A deal an AI agent creates gets the same event
 * when it filled or left empty any field.
 */
class PostStageChange implements AfterSave
{
    public const TYPE = 'OpportunityStageChanged';

    // After the stage history and status are settled.
    public static int $order = 95;

    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Log $log,
        private AiWriters $aiWriters,
        private MetaProvider $metaProvider,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('silent')) return;
        $toId = $entity->get('opportunityStageId');
        $fromId = $entity->isNew() ? null : $entity->getFetched('opportunityStageId');
        if (!$toId || $toId === $fromId) return;
        if (!$entity->isNew() && !$entity->isAttributeChanged('opportunityStageId')) return;
        try {
            $aiFields = $this->aiWriters->isAi($this->user->getId()) ? $this->aiFields($entity) : [];
            if ($entity->isNew() && $aiFields === []) return;
            $to = $this->entityManager->getEntityById('OpportunityStage', $toId);
            $from = $fromId ? $this->entityManager->getEntityById('OpportunityStage', $fromId) : null;
            if (!$to) return;
            $this->entityManager->createEntity('Note', [
                'type' => self::TYPE,
                'parentType' => 'Opportunity',
                'parentId' => $entity->getId(),
                'isInternal' => true,
                'createdById' => $options->get('modifiedById') ?: $this->user->getId(),
                'data' => (object) [
                    'fromStageId' => $from?->getId(),
                    'fromStageName' => $from?->get('name'),
                    'toStageId' => $to->getId(),
                    'toStageName' => $to->get('name'),
                    'status' => $entity->get('status'),
                    'fromStatus' => $entity->isNew() ? null : $entity->getFetched('status'),
                    ...($entity->isNew() ? ['created' => true] : []),
                    ...$aiFields,
                ],
            ]);
        } catch (Throwable $e) {
            // The move itself must stand.
            $this->log->warning('PostStageChange: could not record the move of Opportunity/' . $entity->getId() . ': ' . $e->getMessage());
        }
    }

    /** @return array{filledFields?: list<object>, missingFields?: list<string>} */
    private function aiFields(Entity $entity): array
    {
        $out = [];
        $before = $entity->isNew() ? [] : (array) $entity->getFetched('customFields');
        $after = (array) $entity->get('customFields');
        $filled = [];
        foreach ($after as $key => $value) {
            if (!AiWriters::isEmpty($value) && $value != ($before[$key] ?? null)) $filled[$key] = $value;
        }
        if ($filled !== []) {
            $labels = [];
            foreach ($this->metaProvider->getGroupedMeta('Opportunity', $entity->get('tenantId'))['groups'] as $group) {
                foreach ($group['fields'] as $field) $labels[$field['valueKey']] = $field['label'];
            }
            foreach ($filled as $key => $value) {
                if (isset($labels[$key])) $out['filledFields'][] = (object) ['label' => $labels[$key], 'value' => $value];
            }
        }
        $left = $entity->get(StageRequirements::LEFT_EMPTY);
        $missing = array_values(array_filter(array_map(
            fn ($field) => is_object($field) || is_array($field) ? (((array) $field)['label'] ?? null) : null,
            is_object($left) || is_array($left) ? (((array) $left)['fields'] ?? []) : [],
        )));
        if ($missing !== []) $out['missingFields'] = $missing;
        return $out;
    }
}
