<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Entities\Note;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Tools\Notification\HookProcessor\Params;

/**
 * Feed records' unread state is the notification: only an @mention (NoteMentionHookProcessor,
 * BroadcastFeedMention) or a received message alerts. Never fan out one follower alert per note.
 */
class NoteHookProcessor extends \Espo\Tools\Notification\NoteHookProcessor
{
    public function afterSave(Note $note, Params $params): void
    {
        // Covers AiSession too: mentioning a human never shares a private session or leaks its content.
        if (in_array($note->getParentType(), ['Opportunity', ...ActivityDiscussion::PARENT_TYPES], true)) {
            return;
        }
        parent::afterSave($note, $params);
    }
}
