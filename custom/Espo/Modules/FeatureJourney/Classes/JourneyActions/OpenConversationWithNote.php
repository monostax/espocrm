<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureAutomation\Classes\AutomationActions\OpenConversationWithNote as AutomationNote;
use Espo\Modules\FeatureJourney\Services\JourneyTargetPeople;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Opens (or reuses) the WhatsApp conversation with each person on the journey record
 * and posts a private note there, optionally @mentioning an AI agent. Nothing reaches
 * the customer from this action.
 *
 * Same action as the automation one, with the journey's reach: an Account or
 * Opportunity gets a note in the conversation of every contact in its Contatos
 * ({@see JourneyTargetPeople}), and the note takes `{{…}}` placeholders filled per
 * person (`{{contact.firstName}}`). A person with an empty placeholder, without a
 * WhatsApp number or opted out is left out.
 */
class OpenConversationWithNote extends AutomationNote
{
    public function __construct(
        JourneyWhatsAppOutbound $outbound,
        TenantGuard $tenantGuard,
        EntityManager $entityManager,
        ChatwootApiClient $chatwootApiClient,
        Log $log,
        private JourneyTargetPeople $journeyPeople,
    ) {
        parent::__construct($outbound, $tenantGuard, $entityManager, $chatwootApiClient, $log);
    }

    protected function people(Entity $target, string $tenantId): array
    {
        if (!$this->journeyPeople->fansOut($target)) {
            return parent::people($target, $tenantId);
        }

        return $this->journeyPeople->forTarget($target, $tenantId);
    }

    protected function noteFor(string $note, Entity $target, ?Entity $person): ?string
    {
        $rendered = $this->outbound->renderText($note, $target, $person === $target ? null : $person);

        if ($rendered['empty'] !== []) {
            $who = $person ?? $target;
            $this->log->warning(
                'OpenConversationWithNote: no value for ' . implode(', ', $rendered['empty']) .
                " on {$who->getEntityType()}/{$who->getId()}, skipping."
            );

            return null;
        }

        return $rendered['text'];
    }
}
