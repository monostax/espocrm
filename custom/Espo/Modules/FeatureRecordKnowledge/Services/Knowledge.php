<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Utils\Markdown\Markdown as Renderer;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureRecordKnowledge\Tools\Markdown;

class Knowledge
{
    public function __construct(private EntityManager $em, private Access $access, private Overviews $overviews,
        private ServiceContainer $records) {}

    private function overview(string $type, string $id, string $action = 'read'): Entity
    {
        $parent = $this->access->record($type, $id, $action);
        if ($parent->get('knowledgeRecordType')) throw new BadRequest('Generated overviews are terminal content nodes.');
        $binding = $this->overviews->binding($type, $id);
        // Existing records can be opened before the resumable backfill reaches
        // them. Provision through the same locked, idempotent path as saves.
        if (!$binding) $binding = $this->overviews->ensure($parent);
        if (!$binding) throw new NotFound('Overview is unavailable.');
        $document = $this->access->document($binding->get('overviewDocumentId'), $action);
        if ($document->get('knowledgeRecordType') !== $type || $document->get('knowledgeRecordId') !== $id) throw new Forbidden();
        return $document;
    }

    public function read(string $type, string $id): array
    {
        $document = $this->overview($type, $id);
        $revision = $this->em->getRDBRepository('DocumentRevision')->where([
            'documentId' => $document->getId(), 'revisionNumber' => $document->get('bodyRevisionNumber'),
        ])->findOne();
        $editable = true;
        try { $this->overview($type, $id, 'edit'); } catch (Forbidden|NotFound) { $editable = false; }
        return [
            'documentId' => $document->getId(), 'name' => $document->get('name'), 'body' => (string) $document->get('body'),
            'html' => Renderer::transform((string) $document->get('body')), 'versionNumber' => $document->get('versionNumber'),
            'revision' => $revision ? $this->revisionData($revision) : null, 'editable' => $editable,
        ];
    }

    public function write(string $type, string $id, mixed $body, ?int $version): array
    {
        if ($version === null) throw new BadRequest('X-Version-Number is required.');
        $document = $this->overview($type, $id, 'edit');
        $this->records->get('Document')->update($document->getId(), (object) [
            'body' => Markdown::source($body), 'bodyEditorState' => null,
            'bodyFormat' => 'Markdown', 'bodyAuthoringMode' => 'Markdown',
        ], UpdateParams::create()->withVersionNumber($version));
        return $this->read($type, $id);
    }

    public function export(string $type, string $id): array
    {
        $document = $this->overview($type, $id);
        return ['filename' => "$type-$id.md", 'content' => Markdown::export((string) $document->get('body'), $type, $id, $document->getId())];
    }

    public function import(string $type, string $id, mixed $artifact, ?int $version): array
    {
        if (!is_string($artifact) || strlen($artifact) > 2001000) throw new BadRequest('Invalid Markdown artifact.');
        $document = $this->overview($type, $id, 'edit');
        return $this->write($type, $id, Markdown::import($artifact, $type, $id, $document->getId()), $version);
    }

    public function revision(string $id): array { return $this->revisionData($this->access->revision($id)); }

    private function revisionData(Entity $revision): array
    {
        return [
            'id' => $revision->getId(), 'documentId' => $revision->get('documentId'), 'revisionNumber' => $revision->get('revisionNumber'),
            'body' => (string) $revision->get('body'), 'contentHash' => $revision->get('contentHash'),
            'createdById' => $revision->get('createdById'), 'createdAt' => $revision->get('createdAt'),
        ];
    }

    public function revisions(string $documentId, int $before): array
    {
        $this->access->document($documentId);
        $query = $this->em->getRDBRepository('DocumentRevision')->where(['documentId' => $documentId])->order('revisionNumber', 'DESC')->limit(0, 21);
        if ($before > 0) $query->where(['revisionNumber<' => $before]);
        $rows = iterator_to_array($query->find());
        $more = count($rows) > 20;
        $list = array_map($this->revisionData(...), array_slice($rows, 0, 20));
        return ['list' => $list, 'before' => $more ? end($list)['revisionNumber'] : null];
    }
}
