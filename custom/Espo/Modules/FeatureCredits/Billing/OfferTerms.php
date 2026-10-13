<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Brick\Math\BigDecimal;
use Espo\Modules\FeatureCredits\Accounting\{Amount, GrantInput};
use InvalidArgumentException;
use JsonSerializable;
use stdClass;

/** Immutable commercial value. Catalog publication and acceptance require separate authorized persistence. */
final readonly class OfferTerms implements JsonSerializable
{
    public string $price;
    public string $credits;
    public array $countries;
    public string $termsId;

    public function __construct(
        public string $offerId,
        public string $version,
        public string $productId,
        public string $productVersion,
        public string $operation,
        array $countries,
        public string $currency,
        mixed $price,
        mixed $credits,
        public ?string $interval,
    ) {
        GrantInput::identity($offerId);
        GrantInput::identity($productId);
        foreach ([$version, $productVersion] as $value) {
            if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/D', $value)) {
                throw new InvalidArgumentException('Canonical commercial versions are required.');
            }
        }
        if (!in_array($operation, ['purchase', 'subscription'], true) ||
            ($operation === 'purchase' ? $interval !== null : $interval !== 'month')) {
            throw new InvalidArgumentException('Purchases are one-time; subscriptions require a monthly grant schedule.');
        }
        if (!array_is_list($countries) || !$countries) {
            throw new InvalidArgumentException('At least one eligible billing country is required.');
        }
        foreach ($countries as $country) {
            if (!is_string($country) || !preg_match('/^[A-Z]{2}$/D', $country)) {
                throw new InvalidArgumentException('Billing countries require uppercase two-letter codes.');
            }
        }
        $countries = array_values(array_unique($countries));
        sort($countries, SORT_STRING);
        $this->countries = $countries;
        // Launch currencies have two minor-unit decimals. Never round or convert sale prices.
        if (!in_array($currency, ['BRL', 'USD'], true) || !is_string($price) ||
            !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $price) ||
            !BigDecimal::of($price)->isPositive()) {
            throw new InvalidArgumentException('An explicit positive BRL/USD decimal-string price is required.');
        }
        $this->price = (string) BigDecimal::of($price)->toScale(2);
        $amount = Amount::fromString($credits);
        if ($amount->compareTo(Amount::fromString('0')) < 0 ||
            ($operation === 'purchase' && $amount->compareTo(Amount::fromString('0')) === 0)) {
            throw new InvalidArgumentException('Purchase credits must be positive; monthly allowances cannot be negative.');
        }
        $this->credits = (string) $amount;
        $this->termsId = hash('sha256', json_encode($this->definition(), JSON_THROW_ON_ERROR));
    }

    public function jsonSerialize(): array
    {
        return ['termsId' => $this->termsId] + $this->definition();
    }

    /** Restore original stored terms, never a current catalog lookup. A hash is not payment authority. */
    public static function fromSnapshot(stdClass $snapshot): self
    {
        $fields = get_object_vars($snapshot);
        $expected = ['termsId', 'schema', 'offerId', 'version', 'productId', 'productVersion',
            'operation', 'countries', 'currency', 'price', 'credits', 'interval'];
        if (array_diff(array_keys($fields), $expected) || array_diff($expected, array_keys($fields))) {
            throw new InvalidArgumentException('Invalid commercial snapshot fields.');
        }
        foreach (array_diff($expected, ['countries', 'interval']) as $key) {
            if (!is_string($fields[$key])) {
                throw new InvalidArgumentException('Invalid commercial snapshot value.');
            }
        }
        if ($snapshot->schema !== 'billing-offer-v1' || !is_array($snapshot->countries) ||
            ($snapshot->interval !== null && !is_string($snapshot->interval))) {
            throw new InvalidArgumentException('Invalid commercial snapshot schema.');
        }
        $terms = new self($snapshot->offerId, $snapshot->version, $snapshot->productId, $snapshot->productVersion,
            $snapshot->operation, $snapshot->countries, $snapshot->currency, $snapshot->price,
            $snapshot->credits, $snapshot->interval);
        if (!hash_equals($terms->termsId, $snapshot->termsId)) {
            throw new InvalidArgumentException('Commercial snapshot hash mismatch.');
        }
        return $terms;
    }

    private function definition(): array
    {
        return [
            'schema' => 'billing-offer-v1',
            'offerId' => $this->offerId, 'version' => $this->version,
            'productId' => $this->productId, 'productVersion' => $this->productVersion,
            'operation' => $this->operation, 'countries' => $this->countries,
            'currency' => $this->currency, 'price' => $this->price,
            'credits' => $this->credits, 'interval' => $this->interval,
        ];
    }
}
