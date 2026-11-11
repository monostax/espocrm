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
    /**
     * Seat pricing (schema billing-offer-v2): `plan`, `unitPrice`, `min`, `max`,
     * and optionally `includedCredits` per seat. In a catalog the price is the
     * base (the monthly credits on top of the seats; zero when there are none)
     * and the credits are the base credits. Accepted terms add `quantity` and
     * `basePrice` (plus `baseCredits` with included credits): their price is the
     * monthly total basePrice + unitPrice × quantity and their credits
     * baseCredits + includedCredits × quantity, so every exact-amount check
     * downstream (charge value, payment verification, refunds, the monthly
     * grant) keeps comparing one price and one credit amount.
     *
     * @var ?array{plan: string, unitPrice: string, min: int, max: int, includedCredits?: string,
     *     quantity?: int, basePrice?: string, baseCredits?: string}
     */
    public ?array $seats;

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
        public ?string $tenantId = null,
        ?array $seats = null,
    ) {
        GrantInput::identity($offerId);
        GrantInput::identity($productId);
        if ($tenantId !== null) GrantInput::identity($tenantId);
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
        // A seat offer's catalog price is only its base: the seats make the total positive.
        if (!in_array($currency, ['BRL', 'USD'], true) || !is_string($price) ||
            !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $price) ||
            ($seats === null && !BigDecimal::of($price)->isPositive())) {
            throw new InvalidArgumentException('An explicit positive BRL/USD decimal-string price is required.');
        }
        $this->price = (string) BigDecimal::of($price)->toScale(2);
        $amount = Amount::fromString($credits);
        if ($amount->compareTo(Amount::fromString('0')) < 0 ||
            ($operation === 'purchase' && $amount->compareTo(Amount::fromString('0')) === 0)) {
            throw new InvalidArgumentException('Purchase credits must be positive; monthly allowances cannot be negative.');
        }
        $this->credits = (string) $amount;
        $this->seats = $seats === null ? null : self::seats($seats, $operation, $this->price, $this->credits);
        $this->termsId = hash('sha256', json_encode($this->definition(), JSON_THROW_ON_ERROR));
    }

    /** Accepted terms for a number of seats: the catalog price becomes the base. */
    public function withQuantity(int $quantity): self
    {
        if ($this->seats === null || isset($this->seats['quantity'])) {
            throw new InvalidArgumentException('Only catalog seat offers take a quantity.');
        }
        if ($quantity < $this->seats['min'] || $quantity > $this->seats['max']) {
            throw new InvalidArgumentException('Seat quantity outside the offer range.');
        }
        $total = BigDecimal::of($this->price)->plus(BigDecimal::of($this->seats['unitPrice'])->multipliedBy($quantity));
        $credits = $this->credits;
        $accepted = ['quantity' => $quantity, 'basePrice' => $this->price];
        if (isset($this->seats['includedCredits'])) {
            $credits = (string) Amount::fromString($this->credits)->plus(self::included($this->seats['includedCredits'], $quantity));
            $accepted['baseCredits'] = $this->credits;
        }

        return new self($this->offerId, $this->version, $this->productId, $this->productVersion, $this->operation,
            $this->countries, $this->currency, (string) $total->toScale(2), $credits, $this->interval,
            $this->tenantId, $this->seats + $accepted);
    }

    /** Credits a number of seats bring with them. */
    private static function included(string $perSeat, int $quantity): Amount
    {
        return Amount::fromString((string) BigDecimal::of($perSeat)->multipliedBy($quantity)->toScale(4));
    }

    private static function seats(array $seats, string $operation, string $price, string $credits): array
    {
        $keys = array_keys($seats);
        sort($keys);
        $accepted = isset($seats['quantity']) || isset($seats['basePrice']);
        $included = array_key_exists('includedCredits', $seats);
        $expected = $accepted ? ['basePrice', 'max', 'min', 'plan', 'quantity', 'unitPrice'] : ['max', 'min', 'plan', 'unitPrice'];
        if ($included) {
            $expected = [...$expected, 'includedCredits', ...($accepted ? ['baseCredits'] : [])];
            sort($expected);
        }
        if ($operation !== 'subscription' || $keys !== $expected ||
            !is_string($seats['plan']) || !preg_match('/^[a-z][a-z0-9-]{0,23}$/D', $seats['plan']) ||
            !is_string($seats['unitPrice']) || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $seats['unitPrice']) ||
            !BigDecimal::of($seats['unitPrice'])->isPositive() ||
            !is_int($seats['min']) || !is_int($seats['max']) || $seats['min'] < 1 || $seats['max'] < $seats['min'] || $seats['max'] > 1000) {
            throw new InvalidArgumentException('Seat pricing requires a monthly plan, unit price and seat range.');
        }
        $normalized = ['plan' => $seats['plan'], 'unitPrice' => (string) BigDecimal::of($seats['unitPrice'])->toScale(2),
            'min' => $seats['min'], 'max' => $seats['max']];
        if ($included) {
            $perSeat = Amount::fromString($seats['includedCredits']);
            if ($perSeat->compareTo(Amount::fromString('0')) <= 0) {
                throw new InvalidArgumentException('Included seat credits must be positive.');
            }
            $normalized['includedCredits'] = (string) $perSeat;
        }
        if ($accepted) {
            if (!is_int($seats['quantity']) || $seats['quantity'] < $seats['min'] || $seats['quantity'] > $seats['max'] ||
                !is_string($seats['basePrice']) || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $seats['basePrice'])) {
                throw new InvalidArgumentException('Accepted seat terms require a quantity and base price.');
            }
            $base = BigDecimal::of($seats['basePrice'])->toScale(2);
            $total = $base->plus(BigDecimal::of($normalized['unitPrice'])->multipliedBy($seats['quantity']))->toScale(2);
            if ($total->compareTo(BigDecimal::of($price)) !== 0) {
                throw new InvalidArgumentException('Seat terms price must equal base plus seats.');
            }
            $normalized += ['quantity' => $seats['quantity'], 'basePrice' => (string) $base];
            if ($included) {
                $baseCredits = Amount::fromString($seats['baseCredits']);
                $all = $baseCredits->plus(self::included($normalized['includedCredits'], $seats['quantity']));
                if ($all->compareTo(Amount::fromString($credits)) !== 0) {
                    throw new InvalidArgumentException('Seat terms credits must equal base plus included credits.');
                }
                $normalized['baseCredits'] = (string) $baseCredits;
            }
        }

        return $normalized;
    }

    public function jsonSerialize(): array
    {
        return ['termsId' => $this->termsId] + $this->definition();
    }

    public function availableToTenant(?string $tenantId): bool
    {
        return $this->tenantId === null || $this->tenantId === $tenantId;
    }

    /** Restore original stored terms, never a current catalog lookup. A hash is not payment authority. */
    public static function fromSnapshot(stdClass $snapshot): self
    {
        $fields = get_object_vars($snapshot);
        $expected = ['termsId', 'schema', 'offerId', 'version', 'productId', 'productVersion',
            'operation', 'countries', 'currency', 'price', 'credits', 'interval'];
        if (array_diff(array_keys($fields), [...$expected, 'tenantId', 'seats']) || array_diff($expected, array_keys($fields))) {
            throw new InvalidArgumentException('Invalid commercial snapshot fields.');
        }
        // Stored JSON decodes seats to an object; callers casting a receipt array
        // with (object) keep it an array.
        $seats = $fields['seats'] ?? null;
        if ($seats instanceof stdClass) $seats = get_object_vars($seats);
        if (($snapshot->schema === 'billing-offer-v2') !== ($seats !== null) || ($seats !== null && !is_array($seats))) {
            throw new InvalidArgumentException('Invalid seat terms.');
        }
        if (array_key_exists('tenantId', $fields) && !is_string($fields['tenantId'])) {
            throw new InvalidArgumentException('Invalid private offer tenant.');
        }
        foreach (array_diff($expected, ['countries', 'interval']) as $key) {
            if (!is_string($fields[$key])) {
                throw new InvalidArgumentException('Invalid commercial snapshot value.');
            }
        }
        if (!in_array($snapshot->schema, ['billing-offer-v1', 'billing-offer-v2'], true) || !is_array($snapshot->countries) ||
            ($snapshot->interval !== null && !is_string($snapshot->interval))) {
            throw new InvalidArgumentException('Invalid commercial snapshot schema.');
        }
        $terms = new self($snapshot->offerId, $snapshot->version, $snapshot->productId, $snapshot->productVersion,
            $snapshot->operation, $snapshot->countries, $snapshot->currency, $snapshot->price,
            $snapshot->credits, $snapshot->interval, $fields['tenantId'] ?? null,
            $seats);
        if (!hash_equals($terms->termsId, $snapshot->termsId)) {
            throw new InvalidArgumentException('Commercial snapshot hash mismatch.');
        }
        return $terms;
    }

    private function definition(): array
    {
        return [
            'schema' => $this->seats === null ? 'billing-offer-v1' : 'billing-offer-v2',
            'offerId' => $this->offerId, 'version' => $this->version,
            'productId' => $this->productId, 'productVersion' => $this->productVersion,
            'operation' => $this->operation, 'countries' => $this->countries,
            'currency' => $this->currency, 'price' => $this->price,
            'credits' => $this->credits, 'interval' => $this->interval,
        ] + ($this->tenantId === null ? [] : ['tenantId' => $this->tenantId])
            + ($this->seats === null ? [] : ['seats' => $this->seats]);
    }
}
