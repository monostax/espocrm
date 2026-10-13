<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use InvalidArgumentException;
use stdClass;

final class QuoteInput
{
    public readonly array $data;

    public function __construct(stdClass $input)
    {
        $data = get_object_vars($input);
        $required = ['tenantId', 'targetType', 'targetId', 'products', 'requestKey'];
        if (array_diff($required, array_keys($data)) ||
            array_diff(array_keys($data), [...$required, 'waterfall', 'budget', 'refresh', 'count', 'filters'])) {
            throw new InvalidArgumentException('Invalid enrichment quote fields.');
        }
        foreach (['tenantId', 'targetId'] as $key) {
            if (!is_string($data[$key])) throw new InvalidArgumentException('Invalid quote identity.');
            GrantInput::identity($data[$key]);
        }
        if (!is_string($data['requestKey']) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $data['requestKey'])) {
            throw new InvalidArgumentException('Invalid quote request key.');
        }
        if (!in_array($data['targetType'], ['Contact', 'Account'], true) ||
            !in_array($data['products'], [['email'], ['phone'], ['email', 'phone'], ['company'], ['employees'], ['socios'], ['cnpj']], true) ||
            (in_array($data['products'], [['company'], ['employees'], ['socios'], ['cnpj']], true) && $data['targetType'] !== 'Account')) {
            throw new InvalidArgumentException('Unsupported enrichment target/products.');
        }
        $finder = in_array($data['products'], [['employees'], ['socios']], true);
        if (!$finder && (array_key_exists('count', $data) || array_key_exists('filters', $data))) {
            throw new InvalidArgumentException('Count and filters apply only to Finder.');
        }
        if ($finder) {
            $data += ['count' => 10, 'filters' => []];
            if (!is_int($data['count']) || $data['count'] < 1 || $data['count'] > 50) {
                throw new InvalidArgumentException('Finder count must be an integer from 1 to 50.');
            }
            $filters = $data['filters'] instanceof stdClass ? get_object_vars($data['filters']) : $data['filters'];
            if (!is_array($filters) || array_diff(array_keys($filters), $data['products'] === ['employees'] ? ['titles', 'seniorities'] : [])) {
                throw new InvalidArgumentException('Unsupported Finder filters.');
            }
            foreach ($filters as &$values) {
                if (!is_array($values) || !array_is_list($values) || count($values) > 50) {
                    throw new InvalidArgumentException('Finder filters require bounded lists.');
                }
                foreach ($values as &$value) {
                    if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 128 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                        throw new InvalidArgumentException('Invalid Finder filter value.');
                    }
                    $value = trim($value);
                }
                unset($value);
                $values = array_values(array_unique($values));
                sort($values, SORT_STRING);
            }
            unset($values);
            $filters = array_filter($filters, static fn (array $values): bool => $values !== []);
            ksort($filters, SORT_STRING);
            $data['filters'] = $filters;
        }
        $data += ['waterfall' => false, 'budget' => null, 'refresh' => false];
        if (!is_bool($data['waterfall']) || !is_bool($data['refresh'])) {
            throw new InvalidArgumentException('Boolean enrichment settings required.');
        }
        if ($data['budget'] === null && $data['waterfall']) {
            throw new InvalidArgumentException('Waterfall requires an explicit shared budget.');
        }
        if ($data['budget'] !== null) {
            $budget = Amount::fromString($data['budget']);
            if ($budget->compareTo(Amount::fromString('0')) <= 0) throw new InvalidArgumentException('Positive quote budget required.');
            $data['budget'] = (string) $budget;
        }
        $this->data = $data;
    }

    public function isDiscovery(): bool
    {
        return in_array($this->data['products'], [['employees'], ['socios'], ['cnpj']], true);
    }
}
