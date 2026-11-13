<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Entities\Note;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Assistant;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\ORM\EntityManager;

/** The AI's answer to a WhatsApp turn, sent after the reply's transaction committed (queued by MirrorAssistantReply). */
class SendAssistantReply implements Job
{
    public function __construct(private EntityManager $em, private Settings $settings, private Assistant $assistant) {}

    public function run(Data $data): void
    {
        if (!$this->settings->enabled()) return;
        $reply = $this->em->getEntityById('Note', (string) $data->get('noteId'));
        if ($reply instanceof Note) $this->assistant->reply($reply);
    }
}
