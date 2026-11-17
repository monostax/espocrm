<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\ConflictSilent;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\Stream\NoteUtil;

/**
 * The opportunity watcher's only write path. The backend decides (a decision model, then a
 * confirming model); this service applies one stage move and explains it on the stream, as the
 * AI's own CRM user, or just records what was decided.
 */
class OpportunityWatcher
{
    /** Config key: when the watcher first reached this installation. Deals older than that start switched off. */
    public const STARTED_AT = 'opportunityWatcherStartedAt';
    public const MOVE = 'move';
    public const OUTCOMES = ['none', 'vetoed', 'noCredits', self::MOVE];
    /** A stage a person moved the deal back out of is left alone for this long. */
    private const REVERT_DAYS = 7;

    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Acl $acl,
        private NoteUtil $noteUtil,
    ) {}

    public function decide(string $id, object $body): object
    {
        $opportunity = $this->entityManager->getEntityById('Opportunity', $id);
        if (!$opportunity) throw new NotFound();
        if (!$this->acl->checkEntityEdit($opportunity) || !$this->acl->checkEntityStream($opportunity)) {
            throw new Forbidden('No permission to move this opportunity.');
        }
        $already = $this->entityManager->getRDBRepository('OpportunityWatcherDecision')
            ->where(['runId' => $body->runId, 'opportunityId' => $id])->findOne();
        if ($already) return $this->result($already->get('outcome'), $opportunity);

        if ($body->outcome !== self::MOVE) {
            $this->log($opportunity, $body, $body->outcome);
            return $this->result($body->outcome, $opportunity);
        }
        $blocked = null;
        try {
            $outcome = $this->entityManager->getTransactionManager()->run(fn () => $this->move($id, $body));
        } catch (ConflictSilent $e) {
            // The stage asks for fields nobody filled in. The move is not ours to force.
            $blocked = json_decode($e->getBody() ?? '', true);
            if (($blocked['code'] ?? null) !== 'opportunityStageRequirements') throw $e;
            $outcome = 'blocked';
        }
        if ($outcome === 'blocked') $this->explainBlocked($opportunity, $body, $blocked);
        $this->log($opportunity, $body, $outcome);
        return $this->result($outcome, $this->entityManager->getEntityById('Opportunity', $id) ?? $opportunity);
    }

    private function move(string $id, object $body): string
    {
        $current = $this->entityManager->getRDBRepository('Opportunity')->where(['id' => $id])->forUpdate()->findOne();
        if (!$current || !$current->get('aiWatchEnabled')) return 'off';
        if ($current->get('status') !== 'Open' || $current->get('opportunityStageId') !== $body->expectedStageId) {
            return 'stale';
        }
        $target = $this->entityManager->getEntityById('OpportunityStage', $body->toStageId);
        $from = $this->entityManager->getEntityById('OpportunityStage', $body->expectedStageId);
        if (!$target || !$from || !$target->get('isActive') || $target->get('funnelId') !== $current->get('funnelId')) {
            return 'stale';
        }
        // Never backwards, never sideways: a later open stage, or the won / lost stage.
        $closing = in_array((int) $target->get('probability'), [0, 100], true);
        if (!$closing && (int) $target->get('order') <= (int) $from->get('order')) return 'stale';

        $since = date('Y-m-d H:i:s', time() - self::REVERT_DAYS * 86400);
        $movedBefore = $this->entityManager->getRDBRepository('OpportunityWatcherDecision')->where([
            'opportunityId' => $id, 'toStageId' => $body->toStageId, 'outcome' => 'moved', 'createdAt>=' => $since,
        ])->findOne();
        if ($movedBefore) return 'reverted';

        $current->set('opportunityStageId', $body->toStageId);
        $this->entityManager->saveEntity($current);

        $note = $this->entityManager->getNewEntity('Note');
        $note->set([
            'type' => 'Post',
            'parentType' => 'Opportunity',
            'parentId' => $id,
            'post' => trim($body->reason),
            'isInternal' => true,
            'createdById' => $this->user->getId(),
            'data' => (object) ['opportunityWatcher' => (object) [
                'kind' => 'moved',
                'fromStageId' => $from->getId(),
                'fromStageName' => $from->get('name'),
                'toStageId' => $target->getId(),
                'toStageName' => $target->get('name'),
                'probability' => (float) $body->probability,
                'messageWindow' => $body->messageWindow ?? null,
                'runId' => $body->runId,
            ]],
        ]);
        if (!$this->acl->check($note, 'create')) throw new Forbidden('No permission to post in this stream.');
        $this->noteUtil->handlePostText($note);
        $this->entityManager->saveEntity($note);
        return 'moved';
    }

    /** One post per deal and stage, so a deal stuck on a required field isn't nagged on every message. */
    private function explainBlocked(Entity $opportunity, object $body, array $blocked): void
    {
        $told = $this->entityManager->getRDBRepository('OpportunityWatcherDecision')->where([
            'opportunityId' => $opportunity->getId(), 'toStageId' => $body->toStageId, 'outcome' => 'blocked',
        ])->findOne();
        if ($told) return;
        $fields = array_values(array_filter(array_map(
            fn ($field) => is_array($field) ? ($field['label'] ?? $field['name'] ?? null) : null,
            $blocked['fields'] ?? [],
        )));
        $note = $this->entityManager->getNewEntity('Note');
        $note->set([
            'type' => 'Post',
            'parentType' => 'Opportunity',
            'parentId' => $opportunity->getId(),
            'post' => trim($body->reason),
            'isInternal' => true,
            'createdById' => $this->user->getId(),
            'data' => (object) ['opportunityWatcher' => (object) [
                'kind' => 'blocked',
                'toStageId' => $body->toStageId,
                'toStageName' => $blocked['stageName'] ?? null,
                'missingFields' => $fields,
                'probability' => (float) $body->probability,
                'messageWindow' => $body->messageWindow ?? null,
                'runId' => $body->runId,
            ]],
        ]);
        if (!$this->acl->check($note, 'create')) return;
        $this->noteUtil->handlePostText($note);
        $this->entityManager->saveEntity($note);
    }

    private function log(Entity $opportunity, object $body, string $outcome): void
    {
        $this->entityManager->createEntity('OpportunityWatcherDecision', [
            'opportunityId' => $opportunity->getId(),
            'tenantId' => $opportunity->get('tenantId'),
            'conversationId' => $body->conversationId ?? null,
            'outcome' => $outcome,
            'fromStageId' => $body->expectedStageId ?? $opportunity->get('opportunityStageId'),
            'toStageId' => $body->toStageId ?? null,
            'probability' => $body->probability ?? null,
            'model' => $body->model,
            'reason' => $body->reason ?? null,
            'details' => $body->details ?? null,
            'runId' => $body->runId,
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
    }

    private function result(string $outcome, Entity $opportunity): object
    {
        return (object) [
            'outcome' => $outcome,
            'opportunityStageId' => $opportunity->get('opportunityStageId'),
            'status' => $opportunity->get('status'),
        ];
    }
}
