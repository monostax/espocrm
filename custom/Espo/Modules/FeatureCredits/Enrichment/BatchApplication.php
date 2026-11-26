<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Summarizes purchased contact channels for manual selection. Company data fills the company's
 * empty fields, and partner searches create/link the purchased candidates, both with the
 * purchasing user's permissions.
 */
final class BatchApplication
{
    private const ADDRESS = ['billingAddressStreet', 'billingAddressCity', 'billingAddressState', 'billingAddressCountry',
        'billingAddressPostalCode'];

    public function __construct(private EntityManager $em, private FieldReview $fields, private FieldApplication $application,
        private PurchasedReview $purchases, private CandidateApplication $candidates, private Log $log) {}

    /** @return array<string, int> */
    public function apply(string $tenant, string $quoteId, array $request): array
    {
        return match ($request['products']) {
            ['socios'] => $this->socios($tenant, $quoteId),
            ['company'] => $this->company($tenant, $quoteId),
            default => $this->channelSummary($tenant, $quoteId),
        };
    }

    /** Count distinct values without writing contact fields, identities or application receipts. */
    private function channelSummary(string $tenant, string $quoteId): array
    {
        $q = $this->em->getPDO()->prepare("SELECT id, product FROM enrichment_attempt WHERE tenant_id=? AND quote_id=?
            AND deleted=FALSE AND state='matched' AND purchased_at IS NOT NULL ORDER BY claimed_at, id");
        $q->execute([$tenant, $quoteId]);
        $found = ['email' => [], 'phone' => []];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($found[$row['product']])) continue;
            try {
                $review = $this->fields->read($tenant, $row['id']);
            } catch (Conflict | NotFound | Forbidden) {
                continue;
            }
            if ($review['erased']) continue;
            foreach ((array) $review['values'] as $value) {
                if (is_string($value) && trim($value) !== '') $found[$row['product']][$value] = true;
            }
        }
        return ['emailsFound' => count($found['email']), 'phonesFound' => count($found['phone']),
            'emailsAdded' => 0, 'phonesAdded' => 0, 'needsReview' => ($found['email'] || $found['phone']) ? 1 : 0];
    }

    /** Found company data goes only into empty fields; a filled field keeps its value and the found one stays in history. */
    private function company(string $tenant, string $quoteId): array
    {
        $q = $this->em->getPDO()->prepare("SELECT id FROM enrichment_attempt WHERE tenant_id=? AND quote_id=? AND product='company'
            AND deleted=FALSE AND state='matched' AND purchased_at IS NOT NULL ORDER BY claimed_at, id");
        $q->execute([$tenant, $quoteId]);
        $outcome = ['companyFound' => 0, 'companiesFilled' => 0, 'fieldsFilled' => 0, 'fieldsKept' => 0, 'needsReview' => 0];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                $review = $this->fields->read($tenant, $id);
                if ($review['erased']) continue;
                $outcome['companyFound'] = 1;
                $application = $review['application'];
                if ($application['applied']) continue;
                if (!$application['available']) { $outcome['needsReview'] = 1; continue; }
                $target = $this->em->getEntityById('Account', $review['provenance']['targetId']);
                if (!$target) throw new NotFound('Company is unavailable.');
                $values = (array) $review['values'];
                $current = [];
                foreach ([...array_keys($values), ...self::ADDRESS] as $field) $current[$field] = $target->get($field);
                $plan = self::emptyFields($values, $current, $application['permissions']['editableFields']);
                $outcome['fieldsKept'] += $plan['kept'];
                if (!$plan['fields']) continue;
                $this->application->apply((object) ['tenantId' => $tenant, 'id' => $id, 'source' => 'field',
                    'fields' => (object) $plan['fields'], 'expected' => (object) $plan['expected']]);
                $outcome['companiesFilled'] = 1;
                $outcome['fieldsFilled'] += count($plan['fields']);
            } catch (BadRequest | Conflict | Forbidden | NotFound | InvalidArgumentException) {
                $outcome['needsReview'] = 1;
            } catch (Throwable $e) {
                $this->log->warning('Bulk enrichment could not fill company data.', ['attemptId' => $id, 'exception' => $e]);
                $outcome['needsReview'] = 1;
            }
        }
        return $outcome;
    }

    /**
     * The found values that go into empty, editable fields. The billing address moves as one block: found
     * parts fill it only while every part is empty, so a found city never joins a different street.
     *
     * @return array{fields: array<string, string>, expected: array<string, mixed>, kept: int}
     */
    public static function emptyFields(array $values, array $current, array $editable): array
    {
        $blank = static fn (mixed $value): bool => $value === null || $value === '';
        $addressEmpty = !array_filter(self::ADDRESS, static fn (string $field): bool => !$blank($current[$field] ?? null));
        $fields = []; $expected = []; $kept = 0;
        foreach ($values as $field => $value) {
            if (!is_string($value) || $value === '' || !in_array($field, $editable, true)) continue;
            $now = $current[$field] ?? null;
            if ($blank($now) && ($addressEmpty || !in_array($field, self::ADDRESS, true))) {
                $fields[$field] = $value;
                $expected[$field] = $now;
            } elseif ((string) $now !== $value) {
                $kept++;
            }
        }
        return ['fields' => $fields, 'expected' => $expected, 'kept' => $kept];
    }

    /** Each found person becomes (or is matched to) a Contact linked to the company, like "Criar e vincular". */
    private function socios(string $tenant, string $quoteId): array
    {
        $q = $this->em->getPDO()->prepare("SELECT id FROM enrichment_purchase WHERE tenant_id=? AND quote_id=?
            AND deleted=FALSE AND purchase_kind='candidate' ORDER BY purchased_at, id");
        $q->execute([$tenant, $quoteId]);
        $outcome = ['sociosFound' => 0, 'sociosApplied' => 0, 'needsReview' => 0];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $purchaseId) {
            $outcome['sociosFound']++;
            try {
                $review = $this->purchases->read($tenant, $purchaseId);
                $application = $review['application'];
                if ($application['applied']) { $outcome['sociosApplied']++; continue; }
                $data = self::contactData((array) $review['values'], $application);
                if ($data === null) { $outcome['needsReview']++; continue; }
                $this->candidates->apply(new ApplicationInput((object) ['tenantId' => $tenant, 'purchaseId' => $purchaseId,
                    'createIfUnmatched' => true, 'contactData' => (object) $data]));
                $outcome['sociosApplied']++;
            } catch (BadRequest | Conflict | Forbidden | NotFound | InvalidArgumentException) {
                $outcome['needsReview']++;
            } catch (Throwable $e) {
                // Anything else (a deadlock that kept recurring, say) leaves only this sócio for review:
                // the company's other sócios still apply and the run keeps its counts.
                $this->log->warning('Bulk enrichment could not apply a purchased sócio.',
                    ['purchaseId' => $purchaseId, 'exception' => $e]);
                $outcome['needsReview']++;
            }
        }
        return $outcome;
    }

    /** The creation-form defaults the review screen pre-fills; null when a person must fill anything else. */
    public static function contactData(array $values, array $application): ?array
    {
        $form = $application['creationForm'] ?? null;
        $name = trim((string) ($values['name'] ?? ''));
        if (!$application['available'] || !($form['available'] ?? false) || $name === '') return null;
        $fields = array_column($form['fields'], null, 'name');
        $data = isset($fields['firstName']) ? CandidateProjection::contactName($name) : ['lastName' => $name];
        if (isset($fields['dateOfBirth']) && is_string($values['dateOfBirth'] ?? null) && $values['dateOfBirth'] !== '') {
            $data['dateOfBirth'] = $values['dateOfBirth'];
        }
        foreach ($fields as $field) if ($field['required'] && !isset($data[$field['name']])) return null;
        // The tenant's readable base team is the creation default, even when extra teams exist.
        $teams = array_values(array_intersect($form['defaultTeamsIds'] ?? [], array_column($form['teams'], 'id')));
        if (!$teams && count($form['teams']) === 1) $teams = [$form['teams'][0]['id']];
        if ($form['teamsAllowed'] && $teams) $data['teamsIds'] = $teams;
        elseif ($form['teamsRequired']) return null;
        return $data;
    }

}
