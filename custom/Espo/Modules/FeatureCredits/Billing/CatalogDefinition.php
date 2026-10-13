<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Espo\Modules\FeatureCredits\Accounting\{Amount, GrantInput};
use InvalidArgumentException;
use stdClass;

/** A complete prospective sale catalog. Omitted offers stop new sales, not historical processing. */
final readonly class CatalogDefinition
{
    public string $id;
    public string $version;
    public string $effectiveFrom;
    public array $products;
    /** @var array<string, OfferTerms> */
    public array $offers;
    public array $definition;

    public function __construct(stdClass $input)
    {
        $data = self::fields($input, ['version', 'effectiveFrom', 'products', 'offers']);
        self::version($data['version']);
        if (!is_string($data['effectiveFrom'])) throw new InvalidArgumentException('Catalog start must be a UTC timestamp.');
        GrantInput::timestamp($data['effectiveFrom']);
        $this->version = $data['version'];
        $this->effectiveFrom = $data['effectiveFrom'];
        foreach (['products', 'offers'] as $key) {
            if (!is_array($data[$key]) || !array_is_list($data[$key]) || count($data[$key]) > 100) {
                throw new InvalidArgumentException('Catalog products/offers must be lists of at most 100 entries.');
            }
        }
        $products = [];
        foreach ($data['products'] as $raw) {
            $product = self::fields($raw, ['productId', 'productVersion', 'name', 'operation', 'credits']);
            self::identity($product['productId']);
            self::version($product['productVersion']);
            if (!is_string($product['name']) || trim($product['name']) !== $product['name'] ||
                $product['name'] === '' || strlen($product['name']) > 160 || preg_match('/[\x00-\x1f\x7f]/', $product['name'])) {
                throw new InvalidArgumentException('A bounded product name is required.');
            }
            if (!in_array($product['operation'], ['purchase', 'subscription'], true)) {
                throw new InvalidArgumentException('A product must be a credit package or monthly subscription plan.');
            }
            $credits = Amount::fromString($product['credits']);
            $zero = $credits->compareTo(Amount::fromString('0'));
            if ($zero < 0 || ($zero === 0 && $product['operation'] === 'purchase')) {
                throw new InvalidArgumentException('Invalid product credit entitlement.');
            }
            $product['credits'] = (string) $credits;
            if (isset($products[$product['productId']])) throw new InvalidArgumentException('Duplicate catalog product.');
            $products[$product['productId']] = $product;
        }
        ksort($products, SORT_STRING);
        $this->products = $products;
        $offers = $definitions = [];
        foreach ($data['offers'] as $raw) {
            $offer = self::fields($raw, ['offerId', 'version', 'productId', 'productVersion', 'countries', 'currency', 'price', 'interval']);
            self::identity($offer['offerId']);
            self::identity($offer['productId']);
            self::version($offer['version']);
            self::version($offer['productVersion']);
            if (!is_array($offer['countries']) || !is_string($offer['currency']) ||
                ($offer['interval'] !== null && !is_string($offer['interval']))) {
                throw new InvalidArgumentException('Invalid commercial offer.');
            }
            $product = $products[$offer['productId']] ?? null;
            if (!$product || $product['productVersion'] !== $offer['productVersion']) {
                throw new InvalidArgumentException('Offer must reference its included product version.');
            }
            if (isset($offers[$offer['offerId']])) throw new InvalidArgumentException('Duplicate catalog offer.');
            $terms = new OfferTerms($offer['offerId'], $offer['version'], $offer['productId'], $offer['productVersion'],
                $product['operation'], $offer['countries'], $offer['currency'], $offer['price'], $product['credits'], $offer['interval']);
            $offers[$terms->offerId] = $terms;
            $offer['countries'] = $terms->countries;
            $offer['price'] = $terms->price;
            $definitions[$terms->offerId] = $offer;
        }
        ksort($offers, SORT_STRING);
        ksort($definitions, SORT_STRING);
        $this->offers = $offers;
        $this->definition = [
            'version' => $this->version, 'effectiveFrom' => $this->effectiveFrom,
            'products' => array_values($products), 'offers' => array_values($definitions),
        ];
        $this->id = hash('sha256', json_encode(['billing-catalog-v1', $this->definition], JSON_THROW_ON_ERROR));
    }

    public function receipt(): array
    {
        return [
            'catalogId' => $this->id, 'version' => $this->version, 'effectiveFrom' => $this->effectiveFrom,
            'products' => array_values($this->products),
            'offers' => array_values(array_map(static fn (OfferTerms $terms) => $terms->jsonSerialize(), $this->offers)),
        ];
    }

    public static function productHash(array $product): string
    {
        return hash('sha256', json_encode(['billing-product-v1', $product], JSON_THROW_ON_ERROR));
    }

    private static function fields(mixed $input, array $keys): array
    {
        if (!$input instanceof stdClass) throw new InvalidArgumentException('Catalog entries must be JSON objects.');
        $data = get_object_vars($input);
        if (array_diff(array_keys($data), $keys) || array_diff($keys, array_keys($data))) {
            throw new InvalidArgumentException('Invalid billing catalog fields.');
        }
        // Normalize object-key order before any hash computation.
        return array_replace(array_fill_keys($keys, null), $data);
    }

    private static function identity(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,23}$/D', $value)) {
            throw new InvalidArgumentException('Canonical lowercase commercial identity required.');
        }
    }

    private static function version(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/D', $value)) {
            throw new InvalidArgumentException('Canonical commercial version required.');
        }
    }
}
