<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ReadParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;

/**
 * Applies one settled bulk-enrichment quote the way a person would in the review screen, using the
 * same services and checks (built for the purchasing user by ActorScope). Anything those services
 * refuse is left in the record's enrichment history for manual review.
 */
final class BatchApplication
{
    private const CHANNELS = ['email' => ['emailAddress', 'email'], 'phone' => ['phoneNumber', 'whatsapp']];

    public function __construct(private EntityManager $em, private ServiceContainer $records, private FieldReview $fields,
        private FieldApplication $application, private PurchasedReview $purchases, private CandidateApplication $candidates) {}

    /** @return array<string, int> */
    public function apply(string $tenant, string $quoteId, array $request): array
    {
        return $request['products'] === ['socios'] ? $this->socios($tenant, $quoteId) :
            $this->channels($tenant, $quoteId, $request['targetId']);
    }

    /** Found emails/phones become channels; the best one becomes primary when the record has none. */
    private function channels(string $tenant, string $quoteId, string $contactId): array
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
                if (is_string($value) && trim($value) !== '') $found[$row['product']][$value] ??= $row['id'];
            }
        }
        $outcome = ['emailsFound' => count($found['email']), 'phonesFound' => count($found['phone']),
            'emailsAdded' => 0, 'phonesAdded' => 0, 'needsReview' => 0];
        if (!$found['email'] && !$found['phone']) return $outcome;
        $contact = $this->read($contactId);
        $values = [
            'email' => EnrichmentEmails::personal(array_keys($found['email']), (string) $contact->get('name')),
            'phone' => array_keys($found['phone']),
        ];
        foreach (self::CHANNELS as $product => [$field]) {
            $best = $values[$product][0] ?? null;
            $current = $contact->get($field);
            if ($best === null || (is_string($current) && trim($current) !== '')) continue;
            try {
                $this->application->apply((object) ['tenantId' => $tenant, 'id' => $found[$product][$best], 'source' => 'field',
                    'fields' => (object) [$field => $best], 'expected' => (object) [$field => $current]]);
                $outcome[$product . 'sAdded']++;
            } catch (BadRequest | Conflict | Forbidden | NotFound | InvalidArgumentException) {
                // Same as a refused manual application: the value stays in history for review.
            }
        }
        $added = $this->addChannels($tenant, $contactId, $values);
        $outcome['emailsAdded'] += $added['email'];
        $outcome['phonesAdded'] += $added['phone'];
        $outcome['needsReview'] = $added['refused'];
        return $outcome;
    }

    /** Appends values the contact does not have yet, never touching existing channels. */
    private function addChannels(string $tenant, string $contactId, array $values): array
    {
        $result = ['email' => 0, 'phone' => 0, 'refused' => 0];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $contact = $this->read($contactId);
            $current = $contact->get('channelIdentitiesData');
            // Without channel read access the list is absent; saving a partial list would delete channels.
            if (!is_array($current)) break;
            $identities = array_map(static fn ($item) => (object) (array) $item, $current);
            $new = [];
            $counts = ['email' => 0, 'phone' => 0];
            foreach (self::CHANNELS as $product => [$field, $type]) {
                // Same rule as the review screen's "add": a filled primary field already is the primary.
                $hasPrimary = trim((string) $contact->get($field)) !== '' ||
                    array_filter($identities, static fn ($item) => $item->channelType === $type && $item->isPrimary);
                foreach ($values[$product] as $value) {
                    $key = self::normalize($type, $value);
                    if ($key === null) continue;
                    $exists = array_filter($identities, static fn ($item) => $item->channelType === $type &&
                        self::normalize($type, (string) $item->sourceId) === $key);
                    if ($exists || isset($new[$type . "\0" . $key]) || $this->ownedElsewhere($tenant, $contactId, $type, $key)) continue;
                    $new[$type . "\0" . $key] = (object) ['channelType' => $type, 'sourceId' => $value, 'isPrimary' => !$hasPrimary];
                    $hasPrimary = true;
                    $counts[$product]++;
                }
            }
            if (!$new) return $result;
            try {
                $this->records->get('Contact')->update($contactId,
                    (object) ['channelIdentitiesData' => [...$identities, ...array_values($new)]],
                    UpdateParams::create()->withVersionNumber(self::version($contact)));
                return ['email' => $counts['email'], 'phone' => $counts['phone'], 'refused' => 0];
            } catch (Conflict) {
                // A concurrent edit changed the record; reload once and recompute what is still missing.
                continue;
            } catch (BadRequest | Forbidden | NotFound) {
                break;
            }
        }
        $result['refused'] = 1;
        return $result;
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
        $data = ['lastName' => $name];
        $fields = array_column($form['fields'], null, 'name');
        if (isset($fields['dateOfBirth']) && is_string($values['dateOfBirth'] ?? null) && $values['dateOfBirth'] !== '') {
            $data['dateOfBirth'] = $values['dateOfBirth'];
        }
        foreach ($fields as $field) if ($field['required'] && !isset($data[$field['name']])) return null;
        if ($form['teamsAllowed'] && count($form['teams']) === 1) $data['teamsIds'] = [$form['teams'][0]['id']];
        elseif ($form['teamsRequired']) return null;
        return $data;
    }

    private function read(string $contactId): Entity
    {
        return $this->records->get('Contact')->read($contactId, ReadParams::create())->getEntity();
    }

    private static function version(Entity $contact): ?int
    {
        $version = $contact->get('versionNumber');
        return $version === null ? null : (int) $version;
    }

    private function ownedElsewhere(string $tenant, string $contactId, string $type, string $sourceId): bool
    {
        $q = $this->em->getPDO()->prepare('SELECT 1 FROM contact_channel_identity WHERE tenant_id=? AND channel_type=?
            AND source_id=? AND contact_id<>? AND deleted=FALSE');
        $q->execute([$tenant, $type, $sourceId, $contactId]);
        return (bool) $q->fetchColumn();
    }

    /** Same keys as the Contact channel hook, so duplicates and conflicts are detected before saving. */
    private static function normalize(string $type, string $value): ?string
    {
        return $type === 'email' ? (trim($value) === '' ? null : strtolower(trim($value))) : PhoneNormalizer::normalize($value);
    }
}
