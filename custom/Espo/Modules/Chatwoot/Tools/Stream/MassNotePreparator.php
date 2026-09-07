<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Core\Acl;
use Espo\Core\Utils\Config;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/** Shared by streams and the Note read loader, including create/update responses. */
class MassNotePreparator extends \Espo\Tools\Stream\MassNotePreparator
{
    public function __construct(
        EntityManager $entityManager,
        User $user,
        Config $config,
        private Acl $acl,
    ) {
        parent::__construct($entityManager, $user, $config);
    }

    /**
     * @param iterable<Note> $notes
     */
    public function prepare(iterable $notes): void
    {
        parent::prepare($notes);

        // Capabilities must also be prepared when reactions are disabled.
        foreach ($notes as $note) {
            $isOpportunityPost = $note->getType() === Note::TYPE_POST &&
                $note->getParentType() === 'Opportunity';

            $note->set('opportunityCanEdit', $isOpportunityPost && $this->acl->checkEntityEdit($note));
            $note->set('opportunityCanDelete', $isOpportunityPost && $this->acl->checkEntityDelete($note));
        }
    }
}
