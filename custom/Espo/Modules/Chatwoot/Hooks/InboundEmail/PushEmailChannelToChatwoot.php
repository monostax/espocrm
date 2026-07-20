<?php

namespace Espo\Modules\Chatwoot\Hooks\InboundEmail;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\Chatwoot\Services\EmailChannelBridge;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Push credential/host changes on a linked InboundEmail to Chatwoot Channel::Email.
 *
 * @implements AfterSave<\Espo\Entities\InboundEmail>
 */
class PushEmailChannelToChatwoot implements AfterSave
{
    public static int $order = 80;

    public function __construct(
        private EmailChannelBridge $emailChannelBridge
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get(EmailChannelBridge::SAVE_OPTION_SKIP)) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        // Only push when IMAP/SMTP-relevant attributes change (or new + already linked).
        $relevant = [
            'emailAddress',
            'host',
            'port',
            'security',
            'username',
            'password',
            'useSmtp',
            'smtpHost',
            'smtpPort',
            'smtpSecurity',
            'smtpUsername',
            'smtpPassword',
            'useImap',
        ];

        $changed = false;

        foreach ($relevant as $attr) {
            if ($entity->isAttributeChanged($attr)) {
                $changed = true;
                break;
            }
        }

        if (!$changed && !$entity->isNew()) {
            return;
        }

        $this->emailChannelBridge->pushLinkedMailboxIfNeeded($entity);
    }
}
