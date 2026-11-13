<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Assistant;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Inbound;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;

/**
 * Called by the person's AI (backend switch_workspace, as the person) during a WhatsApp turn in their main chat, when
 * they ask for another of their workspaces. Answers only the outcome: "switched", "picker_sent" or "single".
 */
class WhatsAppAssistant
{
    public function __construct(private User $user, private Settings $settings, private Assistant $assistant, private Inbound $inbound) {}

    public function postActionSwitchWorkspace(Request $request): object
    {
        $noteId = $request->getParsedBody()->sourceNoteId ?? null;
        $turn = $this->settings->enabled() && is_string($noteId) ? $this->assistant->turn($this->user->getId(), $noteId) : null;
        if (!$turn) throw new NotFound();
        return (object) ['status' => $this->inbound->offerWorkspaces($turn['subscription'], $turn['text'], $turn['messageId'], "workspaces:{$noteId}")];
    }
}
