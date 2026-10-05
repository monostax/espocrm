<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

class ContactConversations extends OpportunityConversations
{
    protected function entityType(): string
    {
        return 'Contact';
    }
}
