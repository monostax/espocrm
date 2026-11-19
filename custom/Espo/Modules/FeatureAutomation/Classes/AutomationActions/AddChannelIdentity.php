<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureAutomation\Services\ContactChannelWriter;
use Espo\Modules\FeatureAutomation\Services\PayloadBag;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;

/**
 * Adds the found enrichment value that started the run to its Contact.
 *
 * params:
 *  - channel: phone (phone number + WhatsApp identity) · email (email + email identity) ·
 *    whatsapp (WhatsApp identity only)
 *  - as: result key (default = channel)
 *
 * Writes `payload.channelIdentity.<as>`: added · already_present · not_applicable (the found
 * value is not of this kind) · invalid · rejected (the person marked it as not theirs) ·
 * conflict (it belongs to another contact) · unavailable (receipt erased or gone).
 * Guard it with `when` on the verification results, e.g. only add a phone when WhatsApp
 * says registered and Twilio says verified.
 */
class AddChannelIdentity implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private PayloadBag $payloadBag,
        private ActorScope $actors,
    ) {}

    public function run(ActionContext $context): void
    {
        $channel = (string) ($context->params['channel'] ?? '');
        if (!in_array($channel, ContactChannelWriter::CHANNELS, true)) {
            throw new Error("addChannelIdentity: unknown channel '{$channel}'.");
        }

        $item = $context->record;
        $contact = $context->target;
        if ($contact->getEntityType() !== 'Contact') {
            throw new Error('addChannelIdentity runs on Contact records only.');
        }

        $actor = $context->actor;
        $tenantId = (string) ($context->tenantId ?? '');
        if (!$actor || $tenantId === '') {
            throw new Error('addChannelIdentity needs a run-as user and a workspace.');
        }

        $key = ContactChannelSource::key($context->params['as'] ?? null, $channel);
        $payload = $this->payloadBag->normalize($item->get('payload'));

        $done = $payload['channelIdentity'] ?? null;
        if (is_array($done) && isset($done[$key])) {
            return;
        }

        $source = ContactChannelSource::descriptor(
            ContactChannelSource::ENRICHMENT_RESULT,
            $channel === 'email' ? 'email' : 'phone',
            $payload,
        );

        if ($source === null) {
            $this->record($item, $payload, $key, 'not_applicable');

            return;
        }

        $contactId = (string) $contact->getId();

        try {
            $outcome = $this->actors->run(
                (string) $actor->getId(),
                fn (callable $make) => $make(ContactChannelWriter::class)
                    ->add($tenantId, $contactId, $channel, $source),
            );
        } catch (NotFound | InvalidArgumentException) {
            $outcome = 'unavailable';
        } catch (\Espo\Core\Exceptions\Conflict) {
            // Erased or altered receipt (the write itself reports conflicts as an outcome).
            $outcome = 'unavailable';
        }

        $this->record($item, $payload, $key, $outcome);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function record(Entity $item, array $payload, string $key, string $outcome): void
    {
        $payload['channelIdentity'][$key] = $outcome;
        $item->set('payload', $payload);
        if ($item->hasId() && !str_starts_with((string) $item->getId(), 'sim_')) {
            $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);
        }
    }
}
