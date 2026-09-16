<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Entities\Note;

class MyReactionsService extends \Espo\Tools\Stream\MyReactionsService
{
    protected function allowsMultipleReactions(Note $note): bool
    {
        return $note->getParentType() === 'Opportunity';
    }

    protected function isReactionAllowed(string $type, Note $note): bool
    {
        if (parent::isReactionAllowed($type, $note)) {
            return true;
        }

        // Store a single emoji, including ZWJ families, flags, keycaps and skin tones.
        return $note->getParentType() === 'Opportunity' &&
            mb_strlen($type) <= 64 &&
            preg_match('/\A\X\z/u', $type) === 1 &&
            preg_match('/[\p{Extended_Pictographic}\x{1F1E6}-\x{1F1FF}\x{20E3}]/u', $type) === 1;
    }
}
