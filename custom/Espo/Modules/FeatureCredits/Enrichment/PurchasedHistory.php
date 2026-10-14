<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use InvalidArgumentException;
use PDO;

/** Bounded receipt listing; never enumerates hidden acquisitions or discloses their counts. */
final class PurchasedHistory
{
    public function __construct(private ReviewLock $lock,private Access $access,private PurchasedReview $review,private FieldReview $fields) {}

    public function read(array $params): array
    {
        if (array_diff(array_keys($params),['tenantId','targetType','targetId','product','limit','beforeAt','beforeId']) ||
            !is_string($params['product'] ?? null)) throw new InvalidArgumentException('Invalid purchased history query.');
        $input = new QuoteInput((object) ['tenantId' => $params['tenantId'] ?? null,'targetType' => $params['targetType'] ?? null,
            'targetId' => $params['targetId'] ?? null,'products' => [$params['product']],'requestKey' => 'history']);
        $limit = $params['limit'] ?? 20;
        if (is_string($limit) && preg_match('/\A[1-9][0-9]?\z/',$limit)) $limit = (int) $limit;
        if (!is_int($limit) || $limit < 1 || $limit > 50) throw new InvalidArgumentException('History limit must be 1–50.');
        $cursor = array_key_exists('beforeAt',$params) || array_key_exists('beforeId',$params);
        if ($cursor) {
            if (!is_string($params['beforeAt'] ?? null) || !is_string($params['beforeId'] ?? null)) {
                throw new InvalidArgumentException('Complete history cursor required.');
            }
            GrantInput::timestamp($params['beforeAt']); GrantInput::identity($params['beforeId']);
        }
        // Denial precedes even an empty-list response; source/destination field ACLs are current.
        $this->access->target($input);
        return $this->lock->run($input->data['tenantId'],function (PDO $pdo) use ($input,$params,$cursor,$limit): array {
            $this->access->target($input);
            $table = $input->isDiscovery() ? 'enrichment_purchase' : 'enrichment_attempt';
            $sql = 'SELECT p.id,p.purchased_at FROM ' . $table . ' p JOIN enrichment_quote q
                ON q.id=p.quote_id AND q.tenant_id=p.tenant_id WHERE p.tenant_id=? AND q.target_type=? AND q.target_id=?
                AND p.product=? AND p.deleted=FALSE AND q.deleted=FALSE AND p.purchased_at IS NOT NULL';
            $args = [$input->data['tenantId'],$input->data['targetType'],$input->data['targetId'],$params['product']];
            if ($cursor) {
                $sql .= ' AND (p.purchased_at<? OR (p.purchased_at=? AND p.id<?))';
                array_push($args,$params['beforeAt'],$params['beforeAt'],$params['beforeId']);
            }
            $q = $pdo->prepare($sql . ' ORDER BY p.purchased_at DESC,p.id DESC LIMIT ' . ($limit + 1));
            $q->execute($args); $rows = $q->fetchAll(PDO::FETCH_ASSOC); $items = [];
            foreach ($rows as $row) {
                $read = $input->isDiscovery() ? $this->review->readLocked($pdo,$input->data['tenantId'],$row['id']) :
                    $this->fields->reviewLocked($pdo,$input->data['tenantId'],$row['id']);
                // Include only the authorized Finder name for collapsed history headers.
                if (in_array($params['product'],['employees','socios'],true)) {
                    $read['displayName'] = $read['erased'] ? null : ($read['values']->name ?? null);
                }
                // Full purchased values remain available through explicit review.
                unset($read['values']); $items[] = $read;
            }
            $more = count($items) > $limit;
            if ($more) array_pop($items);
            $last = $more ? $rows[$limit - 1] : null;
            return ['list' => $items,'next' => $last === null ? null : ['beforeAt' => $last['purchased_at'],'beforeId' => $last['id']]];
        });
    }
}
