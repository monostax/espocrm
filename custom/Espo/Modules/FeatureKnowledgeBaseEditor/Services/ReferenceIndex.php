<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;

class ReferenceIndex
{
    public function __construct(private EntityManager $em, private Acl $acl,
        private SelectBuilderFactory $select, private EditorReferences $references) {}

    public function remove(Entity $source): void
    {
        $query = $this->em->getQueryBuilder()->delete()->from('EditorReferenceIndex')
            ->where(['sourceType' => $source->getEntityType(), 'sourceId' => $source->getId()])->build();
        $this->em->getQueryExecutor()->execute($query);
    }

    /** Runs in the source entity's transactional save, after the content is persisted. */
    public function replace(Entity $source): void
    {
        [$field, $state] = References::FIELDS[$source->getEntityType()];
        $refs = References::fromEntity($source);
        $this->remove($source);
        foreach ($refs as $ref) {
            if ($ref['kind'] !== 'record') continue;
            $this->em->createEntity('EditorReferenceIndex', [
                'sourceType' => $source->getEntityType(), 'sourceId' => $source->getId(), 'sourceField' => $field,
                'targetType' => $ref['entityType'], 'targetId' => $ref['recordId'],
            ]);
        }
    }

    public function list(string $type, string $id, string $cursor): array
    {
        $visible = [];
        do {
            $page = $this->scan($type, $id, $cursor);
            $visible = [...$visible, ...$page['list']];
            $cursor = $page['cursor'];
        } while ($cursor && count($visible) <= 20);
        $more = count($visible) > 20;
        $visible = array_slice($visible, 0, 20);
        $next = $more ? end($visible)['indexCursor'] : null;
        foreach ($visible as &$item) unset($item['indexCursor']);
        return ['list' => $visible, 'cursor' => $next];
    }

    private function scan(string $type, string $id, string $cursor): array
    {
        $target = ['kind' => 'record', 'entityType' => $type, 'recordId' => $id];
        if (!References::valid($target)) throw new BadRequest('Invalid target.');
        if (!($this->references->resolve([$target])[0]['available'] ?? false)) throw new Forbidden();
        if ($cursor && !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $cursor)) throw new BadRequest('Invalid cursor.');
        $query = $this->em->getRDBRepository('EditorReferenceIndex')
            ->where(['targetType' => $type, 'targetId' => $id])->order('id')->limit(0, 100);
        if ($cursor) $query->where(['id>' => $cursor]);
        $rows = iterator_to_array($query->find());
        $groups = [];
        foreach ($rows as $row) $groups[$row->get('sourceType')][] = $row->get('sourceId');
        $sources = [];
        foreach ($groups as $sourceType => $ids) {
            if (!isset(References::FIELDS[$sourceType])) continue;
            [$field] = References::FIELDS[$sourceType];
            if (!$this->acl->checkScope($sourceType, 'read') || !$this->acl->checkField($sourceType, $field) ||
                !$this->acl->checkField($sourceType, 'name')) continue;
            try {
                $sql = $this->select->create()->from($sourceType)->withStrictAccessControl()->buildQueryBuilder()
                    ->where(['id' => array_values(array_unique($ids))])->build();
                foreach ($this->em->getRDBRepository($sourceType)->clone($sql)->find() as $source) {
                    if ($this->acl->checkEntityRead($source)) $sources[$sourceType . ':' . $source->getId()] = $source;
                }
            } catch (Forbidden) { continue; }
        }
        $list = [];
        foreach ($rows as $row) {
            $source = $sources[$row->get('sourceType') . ':' . $row->get('sourceId')] ?? null;
            if (!$source) continue;
            // Validate against canonical content too: stale/recovery rows cannot publish a backlink.
            [, $state] = References::FIELDS[$source->getEntityType()];
            try { $refs = References::fromEntity($source); } catch (BadRequest) { continue; }
            if (!in_array(References::url($target), array_map(References::url(...), $refs), true)) continue;
            $list[] = ['entityType' => $source->getEntityType(), 'recordId' => $source->getId(),
                'field' => $row->get('sourceField'), 'label' => $source->get('name'), 'indexCursor' => $row->getId()];
        }
        // No unfiltered totals. Cursor carries no labels or source counts.
        return ['list' => $list, 'cursor' => count($rows) === 100 ? end($rows)->getId() : null];
    }
}
