<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Utils\Markdown\Markdown as Renderer;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureRecordKnowledge\Services\Overviews;
use Espo\Modules\FeatureRecordKnowledge\Services\Knowledge;
use Espo\Modules\FeatureRecordKnowledge\Tools\Markdown;

class Documents
{
    public function __construct(private EntityManager $em, private Access $access,
        private Overviews $overviews, private ServiceContainer $records, private Knowledge $knowledge) {}

    public function aggregate(string $meetingId): ?Entity
    {
        return $this->em->getRDBRepository('MeetingGoogleImport')->where(['meetingId' => $meetingId])->findOne();
    }

    public function destination(Entity $meeting, string $kind): ?Entity
    {
        $id = $kind === 'notes' ? $this->overviews->binding('Meeting', $meeting->getId())?->get('overviewDocumentId')
            : $this->aggregate($meeting->getId())?->get('transcriptDocumentId');
        if (!$id) return null;
        $document = $this->em->getEntityById('Document', $id);
        if (!$document) throw new NotFound('Owned document is unavailable.');
        if ($document->get('knowledgeRecordType') !== 'Meeting' || $document->get('knowledgeRecordId') !== $meeting->getId()) throw new Forbidden();
        return $document;
    }

    public function expectation(Entity $meeting, string $kind, mixed $expected): array
    {
        if (!is_object($expected) || !property_exists($expected, 'documentId') || !property_exists($expected, 'versionNumber')) {
            throw new BadRequest('Reviewed destination precondition is required.');
        }
        $document = $this->destination($meeting, $kind);
        $this->access->text($document);
        if (!$document && $kind !== 'transcript') throw new Conflict('Open and save the overview first.');
        $absence = $kind === 'transcript' && $expected->documentId === null && $expected->versionNumber === null;
        if (!$absence && ($expected->documentId !== $document?->getId() || !is_int($expected->versionNumber))) {
            throw new BadRequest('Destination does not belong to this content kind.');
        }
        // A version can become stale while Start is in flight. Let the fenced
        // worker report that kind's conflict without blocking the other kinds.
        return [$expected->documentId, $expected->versionNumber];
    }

    public function read(int $accountId, string $meetingId): array
    {
        $meeting = $this->access->meeting($accountId, $meetingId);
        $document = $this->destination($meeting, 'transcript');
        if (!$document) return ['exists' => false, 'documentId' => null, 'versionNumber' => null, 'editable' => false, 'body' => '', 'bodyEditorState' => null];
        $this->access->text($document, false);
        $editable = true;
        try { $this->access->meeting($accountId, $meetingId, true); $this->access->text($document); }
        catch (Forbidden|NotFound) { $editable = false; }
        return ['exists' => true, 'documentId' => $document->getId(), 'name' => $document->get('name'),
            'record' => ['name' => $meeting->get('name'), 'iconEditable' => false],
            'body' => (string) $document->get('body'), 'bodyEditorState' => $document->get('bodyEditorState'),
            'bodyAuthoringMode' => $document->get('bodyAuthoringMode'), 'html' => Renderer::transform((string) $document->get('body')),
            'versionNumber' => $document->get('versionNumber'), 'editable' => $editable,
            'revision' => $this->knowledge->revisions($document->getId(), 0)['list'][0] ?? null];
    }

    public function save(int $accountId, string $meetingId, object $content, int $version): array
    {
        $meeting = $this->access->meeting($accountId, $meetingId, true);
        $document = $this->destination($meeting, 'transcript');
        if (!$document) throw new NotFound('No transcript has been imported.');
        $this->access->text($document);
        if (!property_exists($content, 'body') || !is_string($content->bodyEditorState ?? null)) throw new BadRequest('Invalid document content.');
        $this->write($document, Markdown::source($content->body), $content->bodyEditorState, $version);
        return $this->read($accountId, $meetingId);
    }

    private function write(Entity $document, string $body, string $state, int $version): void
    {
        $this->records->get('Document')->update($document->getId(), (object) [
            'body' => $body, 'bodyEditorState' => $state, 'bodyFormat' => 'Markdown', 'bodyAuthoringMode' => 'Lexical',
        ], UpdateParams::create()->withVersionNumber($version));
    }

    /** Caller holds the Meeting, aggregate and fenced attempt locks in the publication transaction. */
    public function publish(Entity $meeting, Entity $aggregate, Entity $attempt, array $parts): array
    {
        $document = $this->destination($meeting, $attempt->get('kind'));
        if ($document) {
            $document = $this->em->getRDBRepository('Document')->where(['id' => $document->getId()])->forUpdate()->findOne();
        }
        $this->access->text($document);
        if ($document?->getId() !== $attempt->get('expectedDocumentId') ||
            ($document && (int) $document->get('versionNumber') !== (int) $attempt->get('expectedVersion'))) throw new Failure('conflict');
        // Empty pages have no saved editor state. Text::append initializes them,
        // while still rejecting nonempty content without a safe editor snapshot.
        $content = Text::append($document?->get('bodyEditorState'), (string) $document?->get('body'), $parts);
        if ($document) {
            $this->write($document, $content['body'], $content['bodyEditorState'], (int) $attempt->get('expectedVersion'));
        } else {
            if ($attempt->get('kind') !== 'transcript') throw new Failure('conflict');
            $document = $this->em->createEntity('Document', [
                'name' => mb_substr((string) $meeting->get('name') . ' — Transcript', 0, 255),
                'contentType' => 'Page', 'bodyFormat' => 'Markdown', 'bodyAuthoringMode' => 'Lexical',
                'body' => $content['body'], 'bodyEditorState' => $content['bodyEditorState'],
                'knowledgeRecordType' => 'Meeting', 'knowledgeRecordId' => $meeting->getId(),
                'assignedUserId' => $meeting->get('assignedUserId'),
            ]);
            $this->overviews->syncAccess($meeting, $document);
            $this->access->text($document);
            $aggregate->set('transcriptDocumentId', $document->getId());
            $this->em->saveEntity($aggregate);
        }
        return $content['warnings'];
    }
}
