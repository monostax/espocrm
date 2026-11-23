<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureAutomation\Services\ContactVerification;
use Espo\Modules\FeatureAutomation\Services\PayloadBag;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\Modules\FeatureCredits\Verification\Kind;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;

/**
 * Runs one paid contact check and waits for its answer.
 *
 * params:
 *  - check: whatsapp_registration (2 credits) · phone_identity (Twilio, 8) · email_deliverability (2)
 *  - source: enrichmentResult (default; the found value that started the run) · contactPrimary
 *  - as: result key (default = check)
 *  - stopWhen: optional short-circuit goal ('valid_email' | 'valid_whatsapp' | 'verified_phone').
 *    When set, candidates for the same Contact run one at a time in this channel family and skip
 *    without spending credits once the Contact already satisfies the goal.
 *
 * Writes `payload.verification.<as>` once decided, for later `when` formulas:
 *  - the check's own outcome (registered / not_registered, verified / not_verified /
 *    insufficient_data, deliverable / risky / undeliverable / unknown)
 *  - not_applicable: the source has no value for this check (an email check on a found phone)
 *  - skipped_already_valid: the Contact already satisfied `stopWhen` (charged nothing)
 *  - unavailable: the check cannot run for this value (reason in `verificationInfo.<as>.reason`,
 *    e.g. ownership_rejected, invalid_phone, missing_name, verification_unavailable)
 *  - failed: the provider gave no answer (charged nothing) or none came within 15 minutes
 *
 * While the check runs the item parks and re-polls every ~20 s (the minute scheduler
 * decides the real cadence). Workspace rate limits park it until the limit frees up.
 * Running out of credits fails the item.
 */
class VerifyContactChannel implements Action
{
    /** Which kind of value each check reads. */
    private const CHECKS = [
        Kind::WHATSAPP => 'phone',
        Kind::PHONE => 'phone',
        Kind::EMAIL => 'email',
    ];

    /** Goal definitions for `stopWhen`: [verification_kind, required_outcome]. */
    private const STOP_GOALS = [
        'valid_email' => [Kind::EMAIL, 'deliverable'],
        'valid_whatsapp' => [Kind::WHATSAPP, 'registered'],
        'verified_phone' => [Kind::PHONE, 'verified'],
    ];

    private const POLL_SECONDS = 20;
    private const SERIAL_WAIT_SECONDS = 15;
    private const GIVE_UP_SECONDS = 900;

    public function __construct(
        private EntityManager $entityManager,
        private PayloadBag $payloadBag,
        private ActorScope $actors,
    ) {}

    public function run(ActionContext $context): void
    {
        $check = (string) ($context->params['check'] ?? '');
        if (!isset(self::CHECKS[$check])) {
            throw new Error("verifyContactChannel: unknown check '{$check}'.");
        }

        $item = $context->record;
        $contact = $context->target;
        if ($contact->getEntityType() !== 'Contact') {
            throw new Error('verifyContactChannel runs on Contact records only.');
        }

        $actor = $context->actor;
        $tenantId = (string) ($context->tenantId ?? '');
        if (!$actor || $tenantId === '') {
            throw new Error('verifyContactChannel needs a run-as user and a workspace.');
        }

        $key = ContactChannelSource::key($context->params['as'] ?? null, $check);
        $payload = $this->payloadBag->normalize($item->get('payload'));

        $decided = $payload['verification'] ?? null;
        if (is_array($decided) && isset($decided[$key])) {
            return;
        }

        $source = ContactChannelSource::descriptor(
            (string) ($context->params['source'] ?? '') ?: ContactChannelSource::ENRICHMENT_RESULT,
            self::CHECKS[$check],
            $payload,
        );

        if ($source === null) {
            $this->decide($item, $payload, $key, 'not_applicable', []);

            return;
        }

        $pending = is_array($payload['_verify'][$key] ?? null) ? $payload['_verify'][$key] : [];
        $since = is_string($pending['since'] ?? null) ? $pending['since'] : gmdate('Y-m-d H:i:s');
        $requestKey = 'automation:' . substr(hash('sha256', $item->getId() . '|' . $key), 0, 40);
        $contactId = (string) $contact->getId();

        $stopWhen = is_string($context->params['stopWhen'] ?? null) ? trim($context->params['stopWhen']) : '';
        if ($stopWhen !== '') {
            if (!isset(self::STOP_GOALS[$stopWhen])) {
                throw new Error("verifyContactChannel: unknown stopWhen '{$stopWhen}'.");
            }
            if ($pending === []) {
                if ($this->contactSatisfiesGoal($tenantId, $contactId, $stopWhen)) {
                    $this->decide($item, $payload, $key, 'skipped_already_valid', [
                        'reason' => 'contact_already_' . $stopWhen,
                    ]);

                    return;
                }
                if ($this->hasEarlierChannelVerificationInFlight($tenantId, $contactId, $item, self::CHECKS[$check])) {
                    throw ActionWaiting::inSeconds(self::SERIAL_WAIT_SECONDS, 'contact_verification_in_flight');
                }
            }
        }

        try {
            $result = $this->actors->run(
                (string) $actor->getId(),
                fn (callable $make) => $make(ContactVerification::class)
                    ->startOrRead($tenantId, $contactId, $check, $source, $requestKey),
            );
        } catch (Conflict $e) {
            $body = json_decode((string) $e->getBody(), true);
            $reason = is_array($body) ? (string) ($body['reason'] ?? '') : '';

            if (in_array($reason, ['rate_limited', 'cooldown_active'], true)) {
                throw ActionWaiting::inSeconds((int) ($body['retryAfterSeconds'] ?? 60), $reason);
            }

            if ($reason === 'context_changed') {
                // The contact changed between reading and starting; read again shortly.
                throw ActionWaiting::inSeconds(5, $reason);
            }

            if ($reason === 'insufficient_credits') {
                throw new Error('Not enough credits for the verification.');
            }

            if ($reason === '') {
                throw $e;
            }

            $this->decide($item, $payload, $key, 'unavailable', ['reason' => $reason]);

            return;
        } catch (NotFound | InvalidArgumentException) {
            // Erased receipt, removed phone, or a value that no longer belongs to this contact.
            $this->decide($item, $payload, $key, 'unavailable', ['reason' => 'source_unavailable']);

            return;
        }

        if (isset($result['unavailable'])) {
            $this->decide($item, $payload, $key, 'unavailable', ['reason' => $result['unavailable']]);

            return;
        }

        $operation = $result['operation'];
        $info = [
            'operationId' => $operation['id'] ?? null,
            'settledCredits' => $operation['settledCredits'] ?? null,
        ];

        if (($operation['state'] ?? null) === 'completed') {
            $outcome = $operation['outcome'] ?? null;
            if (!is_string($outcome) || $outcome === '') {
                $this->decide($item, $payload, $key, 'failed', $info + ['reason' => 'result_hidden']);

                return;
            }

            $this->decide($item, $payload, $key, $outcome, $info);

            return;
        }

        if (($operation['state'] ?? null) === 'failed') {
            $this->decide($item, $payload, $key, 'failed', $info + ['reason' => 'provider_failed']);

            return;
        }

        if (strtotime($since . ' UTC') + self::GIVE_UP_SECONDS < time()) {
            $this->decide($item, $payload, $key, 'failed', $info + ['reason' => 'timeout']);

            return;
        }

        $payload['_verify'][$key] = ['since' => $since, 'operationId' => $operation['id'] ?? null];
        $this->save($item, $payload);

        throw ActionWaiting::inSeconds(self::POLL_SECONDS, 'verification_pending');
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $info
     */
    private function decide(Entity $item, array $payload, string $key, string $outcome, array $info): void
    {
        $payload['verification'][$key] = $outcome;
        $payload['verificationInfo'][$key] = array_filter($info, static fn ($v) => $v !== null);
        unset($payload['_verify'][$key]);
        if (($payload['_verify'] ?? null) === []) {
            unset($payload['_verify']);
        }

        $this->save($item, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function save(Entity $item, array $payload): void
    {
        $item->set('payload', $payload);
        if ($item->hasId() && !str_starts_with((string) $item->getId(), 'sim_')) {
            $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);
        }
    }

    private function contactSatisfiesGoal(string $tenantId, string $contactId, string $stopWhen): bool
    {
        [$kind, $outcome] = self::STOP_GOALS[$stopWhen];
        $pdo = $this->entityManager->getPDO();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM phone_verification_operation
             WHERE tenant_id = ? AND contact_id = ? AND deleted = FALSE
               AND verification_kind = ? AND state = ? AND outcome = ?
             LIMIT 1'
        );
        $stmt->execute([$tenantId, $contactId, $kind, 'completed', $outcome]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Serializes enrichment-triggered checks for the same Contact and channel family (email vs phone)
     * so earlier candidates finish (e.g. WhatsApp -> Twilio -> verified) before later candidates spend credits.
     */
    private function hasEarlierChannelVerificationInFlight(
        string $tenantId,
        string $contactId,
        Entity $item,
        string $product,
    ): bool {
        $pdo = $this->entityManager->getPDO();
        $kinds = $product === 'email' ? [Kind::EMAIL] : [Kind::WHATSAPP, Kind::PHONE];
        $placeholders = implode(',', array_fill(0, count($kinds), '?'));
        $stmt = $pdo->prepare(
            "SELECT 1 FROM phone_verification_operation
             WHERE tenant_id = ? AND contact_id = ? AND deleted = FALSE
               AND verification_kind IN ({$placeholders})
               AND state IN ('admitted', 'claimed', 'awaiting')
             LIMIT 1"
        );
        $stmt->execute([$tenantId, $contactId, ...$kinds]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }

        $automationId = $item->get('automationId');
        $itemId = $item->hasId() ? (string) $item->getId() : '';
        if (!is_string($automationId) || $automationId === '' || $itemId === '' || str_starts_with($itemId, 'sim_')) {
            return false;
        }
        $createdAt = $item->get('createdAt');
        $stmt = $pdo->prepare(
            "SELECT id, payload FROM automation_run_item
             WHERE automation_id = ? AND target_id = ? AND deleted = FALSE
               AND status IN ('Pending', 'Running', 'Waiting') AND id != ?
               AND (created_at < ? OR (created_at = ? AND id < ?))
             LIMIT 25"
        );
        $at = is_string($createdAt) && $createdAt !== '' ? $createdAt : gmdate('Y-m-d H:i:s');
        $stmt->execute([$automationId, $contactId, $itemId, $at, $at, $itemId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $sibling) {
            $siblingPayload = is_string($sibling['payload'] ?? null) ? json_decode($sibling['payload'], true) : null;
            $siblingProduct = is_array($siblingPayload)
                ? ($siblingPayload['_trigger']['enrichment']['product'] ?? $siblingPayload['__trigger']['enrichment']['product'] ?? null)
                : null;
            if ($siblingProduct === $product) {
                return true;
            }
        }

        return false;
    }
}
