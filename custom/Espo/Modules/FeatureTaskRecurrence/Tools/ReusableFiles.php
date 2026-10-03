<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Tools;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\EntityManager;

/** Opt-in Document-backed files; each Task receives its own native attachment record. */
class ReusableFiles
{
    public function __construct(
        private Metadata $metadata,
        private EntityManager $entityManager,
        private Acl $acl,
        private TenantResolver $tenants,
    ) {}

    public function normalize(object $data, string $tenantId): void
    {
        foreach ($this->fields() as $field => $multiple) {
            $attribute = $field . ($multiple ? 'Ids' : 'Id');
            if (!isset($data->$attribute)) continue;
            $ids = $multiple ? $data->$attribute : [$data->$attribute];
            $sources = [];
            foreach ($ids as $id) {
                /** @var \Espo\Entities\Attachment $attachment */
                $attachment = $this->entityManager->getEntityById('Attachment', $id) ?? throw new Forbidden('A reusable file is unavailable.');
                if (!$this->acl->checkEntityRead($attachment)) throw new Forbidden('A reusable file is no longer readable.');
                $source = $this->entityManager->getEntityById('Attachment', $attachment->getSourceId()) ?? throw new Forbidden('A reusable file source is unavailable.');
                $documentId = $source->get('relatedType') === 'Document' ? $source->get('relatedId')
                    : ($source->get('parentType') === 'Document' ? $source->get('parentId') : null);
                if (!$documentId) throw new Forbidden('Reusable recurrence files must come from a Document.');
                $document = $this->entityManager->getEntityById('Document', $documentId) ?? throw new Forbidden();
                if (!$this->acl->checkEntityRead($document) || !$this->acl->checkEntityRead($source)) throw new Forbidden();
                $teams = $document->get('teamsIds');
                if ($teams === null) {
                    $teams = [];
                    foreach ($this->entityManager->getRDBRepository('Document')->getRelation($document, 'teams')->select('id')->find() as $team) $teams[] = $team->getId();
                }
                $owner = $document->get('tenantId') ?: $this->tenants->resolveUniqueFromTeamIds($teams);
                if ($owner !== $tenantId) throw new Forbidden('A reusable file belongs to another workspace.');
                $sources[] = $source->getId();
            }
            $data->$attribute = $multiple ? array_values(array_unique($sources)) : ($sources[0] ?? null);
        }
    }

    public function copy(object $data): object
    {
        $result = clone $data;
        /** @var \Espo\Repositories\Attachment $repository */
        $repository = $this->entityManager->getRepository('Attachment');
        foreach ($this->fields() as $field => $multiple) {
            $attribute = $field . ($multiple ? 'Ids' : 'Id');
            if (!isset($result->$attribute)) continue;
            $ids = $multiple ? $result->$attribute : [$result->$attribute];
            $copies = [];
            foreach ($ids as $id) {
                /** @var \Espo\Entities\Attachment $source */
                $source = $this->entityManager->getEntityById('Attachment', $id) ?? throw new Forbidden('A reusable file is unavailable.');
                if (!$this->acl->checkEntityRead($source)) throw new Forbidden();
                $copy = $repository->getCopiedAttachment($source);
                $copy->set('field', $field);
                $this->entityManager->saveEntity($copy);
                $copies[] = $copy->getId();
            }
            $result->$attribute = $multiple ? $copies : ($copies[0] ?? null);
        }
        return $result;
    }

    private function fields(): array
    {
        $fields = [];
        foreach ($this->metadata->get(['entityDefs', 'Task', 'fields']) ?? [] as $field => $defs) {
            if (!($defs['recurrenceReusable'] ?? false)) continue;
            if (in_array($defs['type'] ?? '', ['file', 'image', 'attachmentMultiple'], true)) {
                $fields[$field] = $defs['type'] === 'attachmentMultiple';
            }
        }
        return $fields;
    }
}
