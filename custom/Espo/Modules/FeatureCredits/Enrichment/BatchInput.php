<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use InvalidArgumentException;
use stdClass;

/** A bulk enrichment request: one product with one set of settings over up to 500 records of one type. */
final class BatchInput
{
    public const MAX_RECORDS = 500;
    public const MAX_PREVIEW = 50;
    private const PRODUCTS = ['Contact' => ['email' => ['email'], 'phone' => ['phone'], 'combined' => ['email', 'phone']],
        'Account' => ['socios' => ['socios']]];

    /** @var list<string> */
    public readonly array $targetIds;
    /** Quote settings shared by every record (tenant/target/requestKey are added per record). */
    public readonly array $settings;
    public readonly array $data;

    public function __construct(stdClass $input, private int $limit = self::MAX_RECORDS, bool $keyed = false)
    {
        $data = get_object_vars($input);
        $allowed = ['tenantId', 'targetType', 'targetIds', 'product', 'sources', 'count', ...($keyed ? ['requestKey'] : [])];
        if (array_diff(array_keys($data), $allowed) || !is_string($data['tenantId'] ?? null) ||
            !is_string($data['targetType'] ?? null) || !is_string($data['product'] ?? null) ||
            !isset(self::PRODUCTS[$data['targetType']][$data['product']])) {
            throw new InvalidArgumentException('Invalid bulk enrichment request.');
        }
        GrantInput::identity($data['tenantId']);
        if ($keyed && (!is_string($data['requestKey'] ?? null) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $data['requestKey']))) {
            throw new InvalidArgumentException('Bulk enrichment request key required.');
        }
        $ids = $data['targetIds'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > $this->limit) {
            throw new InvalidArgumentException("Select between 1 and {$this->limit} records.");
        }
        foreach ($ids as $id) {
            if (!is_string($id)) throw new InvalidArgumentException('Invalid record.');
            GrantInput::identity($id);
        }
        $products = self::PRODUCTS[$data['targetType']][$data['product']];
        $settings = ['products' => $products, 'refresh' => true];
        if ($data['product'] === 'socios') {
            if (array_key_exists('sources', $data)) throw new InvalidArgumentException('Sources apply to email/phone lookups.');
            $count = $data['count'] ?? 10;
            if (!is_int($count) || $count < 1 || $count > 50) throw new InvalidArgumentException('Partner limit must be 1 to 50.');
            $settings['count'] = $count;
        } else {
            if (array_key_exists('count', $data)) throw new InvalidArgumentException('Count applies to partner searches.');
            $settings['sources'] = SelectedProviders::normalize($data['sources'] ?? null);
        }
        $this->targetIds = array_values(array_unique($ids));
        $this->settings = $settings;
        $this->data = $data;
    }

    public function tenantId(): string { return $this->data['tenantId']; }
    public function targetType(): string { return $this->data['targetType']; }
    public function product(): string { return $this->data['product']; }

    /** Quote base for one record, before per-record source filtering in Quotes::batchPlan. */
    public static function base(string $tenantId, string $targetType, string $targetId, array $settings): array
    {
        return [...$settings, 'tenantId' => $tenantId, 'targetType' => $targetType, 'targetId' => $targetId];
    }
}
