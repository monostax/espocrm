<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;

/**
 * Summarizes purchased contact channels for manual selection. Company partner searches also
 * create/link the purchased candidates using the purchasing user's permissions.
 */
final class BatchApplication
{
    public function __construct(private EntityManager $em, private FieldReview $fields,
        private PurchasedReview $purchases, private CandidateApplication $candidates) {}

    /** @return array<string, int> */
    public function apply(string $tenant, string $quoteId, array $request): array
    {
        return $request['products'] === ['socios'] ? $this->socios($tenant, $quoteId) :
            $this->channelSummary($tenant, $quoteId);
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
