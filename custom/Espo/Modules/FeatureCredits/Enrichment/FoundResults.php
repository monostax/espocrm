<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureAutomation\Services\AutomationEventDispatcher;
use Throwable;

/**
 * Tells automations about each email or phone that enrichment found for a Contact.
 *
 * Execution collects the Contact email/phone results inside the transaction that marks them
 * purchased, and announces them only after that transaction commits: one `enrichment.found`
 * signal per found value, subject = the Contact. The signal carries the receipt id, product
 * and value index, never the value; actions resolve it from the encrypted receipt as their
 * run-as user. Announcing never breaks enrichment: failures are logged and dropped.
 */
class FoundResults
{
    public const SIGNAL = 'enrichment.found';

    /** Same bound as a purchased-value index in verification sources. */
    private const MAX_INDEX = 100;

    /** @var array<string, array{contactId: string, attemptId: string, product: string, provider: string, ciphertext: string}> */
    private array $pending = [];

    private ?AutomationEventDispatcher $automations = null;

    public function __construct(
        private Crypt $crypt,
        private InjectableFactory $injectableFactory,
        private Log $log,
    ) {}

    public function reset(): void
    {
        $this->pending = [];
    }

    /**
     * Inside the closing transaction, for an attempt about to be marked purchased.
     *
     * @param array<string, mixed> $terms decoded quote terms
     * @param array<string, mixed> $attempt enrichment_attempt row
     */
    public function collect(array $terms, array $attempt): void
    {
        $contactId = $terms['request']['targetId'] ?? null;
        if (($terms['request']['targetType'] ?? null) !== 'Contact' || !is_string($contactId) ||
            !in_array($attempt['product'] ?? null, ['email', 'phone'], true) ||
            !is_string($attempt['result_ciphertext'] ?? null)) {
            return;
        }

        $this->pending[(string) $attempt['id']] = [
            'contactId' => $contactId,
            'attemptId' => (string) $attempt['id'],
            'product' => (string) $attempt['product'],
            'provider' => (string) ($attempt['provider'] ?? ''),
            'ciphertext' => $attempt['result_ciphertext'],
        ];
    }

    /** After commit. */
    public function announce(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        if ($pending === []) {
            return;
        }

        try {
            // Built only when there is something to announce: the automation runtime is heavy.
            $this->automations ??= $this->injectableFactory->create(AutomationEventDispatcher::class);
            if (!$this->automations->hasSignalListeners(self::SIGNAL)) {
                return;
            }
        } catch (Throwable $e) {
            $this->log->error('Enrichment found signal failed: ' . $e->getMessage());

            return;
        }

        foreach ($pending as $row) {
            try {
                $this->announceAttempt($row);
            } catch (Throwable $e) {
                $this->log->error('Enrichment found signal failed for attempt ' . $row['attemptId'] . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array{contactId: string, attemptId: string, product: string, provider: string, ciphertext: string} $row
     */
    private function announceAttempt(array $row): void
    {
        $values = json_decode($this->crypt->decrypt($row['ciphertext']), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($values)) {
            return;
        }

        // Same order as the receipt's value list that verification indexes into.
        foreach (array_slice(array_values($values), 0, self::MAX_INDEX + 1) as $index => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $this->automations->dispatchSignal(self::SIGNAL, 'Contact', $row['contactId'], [
                'enrichment' => [
                    'attemptId' => $row['attemptId'],
                    'product' => $row['product'],
                    'provider' => $row['provider'],
                    'index' => $index,
                ],
            ]);
        }
    }
}
