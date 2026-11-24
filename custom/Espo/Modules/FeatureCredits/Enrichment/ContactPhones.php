<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Verification\Kind;
use Espo\Modules\FeatureCredits\Verification\Operations;
use PDO;

/**
 * The phones enrichment bought for one Contact, with their latest ownership and WhatsApp
 * checks, for pickers outside the enrichment panel. Same access as the panel's history,
 * review and verification context: without it the list is empty.
 */
class ContactPhones
{
    /** Verification context takes at most 50 sources per call. */
    private const MAX = 50;

    public function __construct(private ReviewLock $lock, private Access $access, private FieldReview $fields,
        private Operations $operations) {}

    /**
     * @return list<array{value: string, ownership: ?string, whatsapp: ?string}> newest purchase first;
     *   ownership = verified|not_verified|insufficient_data|rejected, whatsapp = registered|not_registered
     */
    public function list(string $tenant, string $contactId): array
    {
        $input = new QuoteInput((object) ['tenantId' => $tenant, 'targetType' => 'Contact', 'targetId' => $contactId,
            'products' => ['phone'], 'requestKey' => 'history']);
        try {
            $this->access->target($input);
            $sources = $this->lock->run($tenant, function (PDO $pdo) use ($input, $tenant, $contactId): array {
                $this->access->target($input);
                $q = $pdo->prepare("SELECT p.id FROM enrichment_attempt p JOIN enrichment_quote q
                    ON q.id=p.quote_id AND q.tenant_id=p.tenant_id WHERE p.tenant_id=? AND q.target_type='Contact'
                    AND q.target_id=? AND p.product='phone' AND p.deleted=FALSE AND q.deleted=FALSE
                    AND p.purchased_at IS NOT NULL ORDER BY p.purchased_at DESC, p.id DESC LIMIT 20");
                $q->execute([$tenant, $contactId]);
                $sources = [];
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    try {
                        $saved = $this->fields->readLocked($pdo, $tenant, (string) $id);
                    } catch (Conflict | NotFound) {
                        continue;
                    }
                    if ($saved['erased']) {
                        continue;
                    }
                    foreach (array_values($saved['values']) as $index => $value) {
                        $value = is_string($value) ? trim($value) : '';
                        if ($value !== '' && !isset($sources[$value]) && count($sources) < self::MAX) {
                            $sources[$value] = ['key' => 's' . count($sources), 'kind' => 'purchased',
                                'receiptId' => (string) $id, 'index' => $index];
                        }
                    }
                }
                return $sources;
            });
        } catch (Forbidden | NotFound) {
            return [];
        }
        if ($sources === []) {
            return [];
        }

        $rows = [];
        foreach ([Kind::PHONE, Kind::WHATSAPP] as $kind) {
            try {
                $rows[$kind] = $this->operations->context((object) ['tenantId' => $tenant, 'contactId' => $contactId,
                    'sources' => array_values($sources)], $kind)['rows'];
            } catch (Forbidden) {
                $rows[$kind] = [];
            }
        }

        $list = [];
        foreach ($sources as $value => $source) {
            $phone = $rows[Kind::PHONE][$source['key']] ?? [];
            $whatsapp = $rows[Kind::WHATSAPP][$source['key']] ?? [];
            $list[] = [
                'value' => (string) $value,
                'ownership' => ($phone['ownershipStatus'] ?? null) === 'rejected' || ($phone['reason'] ?? null) === 'ownership_rejected'
                    ? 'rejected' : ($phone['currentResult']['outcome'] ?? null),
                'whatsapp' => $whatsapp['currentResult']['outcome'] ?? null,
            ];
        }

        return $list;
    }
}
