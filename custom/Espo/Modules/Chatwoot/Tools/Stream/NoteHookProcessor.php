<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Entities\Note;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Tools\Notification\HookProcessor\Params;

/** Opportunity unread state is the notification; do not fan out one CRM alert per message. */
class NoteHookProcessor extends \Espo\Tools\Notification\NoteHookProcessor
{
    public function afterSave(Note $note, Params $params): void
    {
        // Mentioning a human never shares a private session or leaks its content in notifications.
        if ($note->getParentType() === 'AiSession') return;
        if (in_array($note->getType(), OpportunityStreamEvents::EVENT_TYPES, true)) {
            return;
        }
        parent::afterSave($note, $params);
    }
}
