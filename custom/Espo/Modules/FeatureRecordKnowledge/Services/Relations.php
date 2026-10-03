<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureRecordKnowledge\Tools\Evidence;
use Espo\Modules\FeatureRecordKnowledge\Tools\Predicates;

class Relations
{
    public function __construct(private EntityManager $em, private Access $access, private User $user, private NativeRelations $native) {}

    public function submit(object $input, bool $manual = false): array
    {
        if ($manual && $this->user->isApi()) throw new Forbidden('API assistants submit proposals for human confirmation.');
        $allowed = ['subjectType', 'subjectId', 'objectType', 'objectId', 'predicate', 'sourceRevisionId', 'idempotencyKey',
            'qualifiers', 'evidenceQuote', 'evidenceStart', 'evidenceEnd'];
        if (array_diff(array_keys(get_object_vars($input)), $allowed)) throw new BadRequest('Unknown proposal properties.');
        $required = ['subjectType', 'subjectId', 'objectType', 'objectId', 'predicate', 'sourceRevisionId', 'idempotencyKey'];
        foreach ($required as $field) {
            if (!is_string($input->$field ?? null) || $input->$field === '') throw new BadRequest("$field is required.");
        }
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $input->idempotencyKey)) throw new BadRequest('Invalid idempotency key.');
        [$predicate, $qualifiers] = Predicates::validate($input->predicate, $input->subjectType, $input->objectType, $input->qualifiers ?? (object) []);
        $this->access->record($input->subjectType, $input->subjectId, $manual ? 'edit' : 'read');
        $this->access->record($input->objectType, $input->objectId);
        $revision = $this->access->revision($input->sourceRevisionId);
        [$start, $end] = Evidence::validate((string) $revision->get('body'), $input->evidenceQuote ?? null,
            $input->evidenceStart ?? null, $input->evidenceEnd ?? null);
        $values = [
            'subjectType' => $input->subjectType, 'subjectId' => $input->subjectId, 'objectType' => $input->objectType, 'objectId' => $input->objectId,
            'predicate' => $predicate, 'qualifiers' => $qualifiers, 'sourceDocumentId' => $revision->get('documentId'),
            'sourceRevisionId' => $revision->getId(), 'evidenceQuote' => $input->evidenceQuote, 'evidenceStart' => $start, 'evidenceEnd' => $end,
            'origin' => $manual ? 'manual' : 'assistant',
        ];
        $key = hash('sha256', $this->user->getId() . ':' . $input->idempotencyKey);
        $hash = hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $this->em->getTransactionManager()->run(function () use ($values, $key, $hash, $manual) {
            // Actor-scoped keys can be retried against different documents concurrently.
            $this->em->getRDBRepository('User')->select(['id'])->where(['id' => $this->user->getId()])->forUpdate()->findOne();
            // Lock the source before comparing current evidence or submitting decisions.
            $document = $this->em->getRDBRepository('Document')->select(['id', 'body', 'bodyRevisionNumber'])->where(['id' => $values['sourceDocumentId']])->forUpdate()->findOne();
            if (!$document) throw new NotFound();
            $existing = $this->em->getRDBRepository('RecordRelation')->where(['submissionKey' => $key])->findOne();
            if ($existing) {
                $this->access->claim($existing);
                if ($existing->get('submissionHash') !== $hash) throw new Conflict('Idempotency key was already used for another proposal.');
                return $this->data($existing);
            }
            $anchor = Evidence::anchor((string) $document->get('body'), $values['evidenceQuote']);
            $current = $this->em->getRDBRepository('DocumentRevision')->where([
                'documentId' => $document->getId(), 'revisionNumber' => $document->get('bodyRevisionNumber'),
            ])->findOne();
            // An explicitly selected span in the current revision is already anchored,
            // even when that quote occurs more than once.
            if ($current?->getId() === $values['sourceRevisionId']) $anchor = [$values['evidenceStart'], $values['evidenceEnd']];
            if ($manual && !$anchor) throw new Conflict('Evidence is no longer supported by the current document.');
            $claim = $this->em->createEntity('RecordRelation', [...$values,
                'submissionKey' => $key, 'submissionHash' => $hash,
                'status' => $anchor ? ($manual ? 'confirmed' : 'suggested') : 'stale',
                'anchorRevisionId' => $anchor ? $current?->getId() : null, 'anchorStart' => $anchor[0] ?? null, 'anchorEnd' => $anchor[1] ?? null,
                'decidedById' => $manual ? $this->user->getId() : null, 'decidedAt' => $manual ? gmdate('Y-m-d H:i:s') : null,
            ]);
            return $this->data($claim);
        });
    }

    public function decide(string $id, string $status): array
    {
        if ($this->user->isApi()) throw new Forbidden('A human CRM user must confirm or reject proposals.');
        if (!in_array($status, ['confirmed', 'rejected'], true)) throw new BadRequest('Decision must be confirmed or rejected.');
        $claim = $this->em->getEntityById('RecordRelation', $id);
        if (!$claim) throw new NotFound();
        $this->access->claim($claim, true);
        return $this->em->getTransactionManager()->run(function () use ($claim, $status) {
            // Same lock order as document revision maintenance: document, then claim.
            $document = $this->em->getRDBRepository('Document')->select(['id', 'body'])->where(['id' => $claim->get('sourceDocumentId')])->forUpdate()->findOne();
            $locked = $this->em->getRDBRepository('RecordRelation')->select(['id'])->where(['id' => $claim->getId()])->forUpdate()->findOne();
            if (!$document || !$locked) throw new NotFound();
            $claim = $this->em->getEntityById('RecordRelation', $locked->getId());
            $this->access->claim($claim, true);
            if ($claim->get('status') === $status) return $this->data($claim);
            if ($claim->get('status') !== 'suggested') throw new Conflict('Only suggested claims can be decided.');
            if ($status === 'confirmed') {
                Evidence::validate((string) $document->get('body'), $claim->get('evidenceQuote'), $claim->get('anchorStart'), $claim->get('anchorEnd'));
            }
            $claim->set(['status' => $status, 'decidedById' => $this->user->getId(), 'decidedAt' => gmdate('Y-m-d H:i:s')]);
            $this->em->saveEntity($claim);
            return $this->data($claim);
        });
    }

    public function list(string $type, string $id, string $direction = 'all', string $status = '', string $cursor = ''): array
    {
        $this->access->record($type, $id);
        if (!in_array($direction, ['all', 'incoming', 'outgoing'], true) ||
            !in_array($status, ['', 'suggested', 'confirmed', 'rejected', 'stale'], true)) throw new BadRequest('Invalid relation filter.');
        if ($cursor && !preg_match('/^[rn]:[a-zA-Z0-9_-]{0,64}$/D', $cursor)) throw new BadRequest('Invalid cursor.');
        $list = [];
        if (!str_starts_with($cursor, 'n:')) {
            $where = [];
            if ($direction !== 'incoming') $where[] = ['subjectType' => $type, 'subjectId' => $id];
            if ($direction !== 'outgoing') $where[] = ['objectType' => $type, 'objectId' => $id];
            $query = $this->em->getRDBRepository('RecordRelation')->where(['OR' => $where])->order('id')->limit(0, 100);
            if ($status) $query->where(['status' => $status]);
            if ($cursor) $query->where(['id>' => substr($cursor, 2)]);
            $rows = iterator_to_array($query->find());
            foreach ($rows as $claim) {
                try { $this->access->claim($claim); } catch (Forbidden|NotFound|BadRequest) { continue; }
                $item = $this->data($claim);
                $item['direction'] = $claim->get('subjectType') === $type && $claim->get('subjectId') === $id ? 'outgoing' : 'incoming';
                $list[] = $item;
                if (count($list) === 20) return ['list' => $list, 'cursor' => 'r:' . $claim->getId()];
            }
            if (count($rows) === 100) return ['list' => $list, 'cursor' => 'r:' . end($rows)->getId()];
        }
        if ($status && $status !== 'confirmed') return ['list' => $list, 'cursor' => null];
        $native = $this->native->page($type, $id, $direction, str_starts_with($cursor, 'n:') ? substr($cursor, 2) : '', 20 - count($list));
        return ['list' => [...$list, ...$native['list']], 'cursor' => $native['cursor'] ? 'n:' . $native['cursor'] : null];
    }

    private function data(Entity $claim): array
    {
        $values = [];
        foreach (['subjectType', 'subjectId', 'objectType', 'objectId', 'predicate', 'qualifiers', 'status', 'origin', 'sourceDocumentId',
            'sourceRevisionId', 'evidenceQuote', 'evidenceStart', 'evidenceEnd', 'anchorRevisionId', 'anchorStart', 'anchorEnd',
            'createdById', 'createdAt', 'decidedById', 'decidedAt'] as $field) $values[$field] = $claim->get($field);
        $subject = $this->access->record($values['subjectType'], $values['subjectId']);
        $object = $this->access->record($values['objectType'], $values['objectId']);
        $editable = !$this->user->isApi();
        if ($editable) {
            try { $this->access->claim($claim, true); } catch (Forbidden|NotFound) { $editable = false; }
        }
        return ['id' => $claim->getId(), ...$values, 'subjectLabel' => $subject->get('name'), 'objectLabel' => $object->get('name'),
            'editable' => $editable && $claim->get('status') === 'suggested'];
    }
}
