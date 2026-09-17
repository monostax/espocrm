<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Core\Acl;
use Espo\Core\Utils\Config;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Modules\Chatwoot\Services\OpportunityThreadState;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Tools\Activities\Access;

/** Shared by streams and the Note read loader, including create/update responses. */
class MassNotePreparator extends \Espo\Tools\Stream\MassNotePreparator
{
    public function __construct(
        EntityManager $entityManager,
        User $user,
        Config $config,
        private Acl $acl,
        private OpportunityThreadState $threads,
        private ActivityDiscussion $activities,
    ) {
        parent::__construct($entityManager, $user, $config);
    }

    /** @param iterable<Note> $notes */
    protected function noAvailableReactions(iterable $notes): bool
    {
        foreach ($notes as $note) {
            if (in_array($note->getParentType(), ['Opportunity', ...Access::TYPES], true)) {
                return false;
            }
        }

        return parent::noAvailableReactions($notes);
    }

    /**
     * @param iterable<Note> $notes
     */
    public function prepare(iterable $notes): void
    {
        parent::prepare($notes);

        $roots = [];
        foreach ($notes as $note) {
            if ($note->getType() === Note::TYPE_POST && $note->getParentType() === 'Opportunity' &&
                !$note->get('opportunityThreadRootId')) {
                $roots[] = $note->getId();
            }
        }
        $summaries = $this->threads->summaries($roots);
        $activityRoots = [];
        foreach ($notes as $note) {
            if ($note->getType() === Note::TYPE_POST && in_array($note->getParentType(), Access::TYPES, true) && !$note->get('opportunityThreadRootId')) {
                $activityRoots[] = $note->getId();
            }
        }
        $summaries += $this->activities->summaries($activityRoots);

        // Capabilities must also be prepared when reactions are disabled.
        foreach ($notes as $note) {
            $isOpportunityPost = $note->getType() === Note::TYPE_POST &&
                in_array($note->getParentType(), ['Opportunity', ...Access::TYPES], true);

            $deleted = $note->get('opportunityPostDeleted');
            $note->set('opportunityCanEdit', $isOpportunityPost && !$deleted && $this->acl->checkEntityEdit($note));
            $note->set('opportunityCanDelete', $isOpportunityPost && !$deleted && $this->acl->checkEntityDelete($note));
            if (isset($summaries[$note->getId()])) {
                $note->set('opportunityThreadSummary', (object) $summaries[$note->getId()]);
            }
        }
    }
}
