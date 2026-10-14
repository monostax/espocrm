<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Enrichment\Catalog;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** Global price/routing administration, never tenant self-service or supplier activation. */
final class CreditEnrichmentConfiguration
{
    public function __construct(private User $user, private Catalog $catalog) {}

    public function postActionPublish(Request $request, Response $response): object
    {
        $this->authorize($response);
        $raw = $request->getBodyContents() ?? '';
        if (strlen($raw) > 16384) throw new BadRequest('Configuration is too large.');
        try {
            $input = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            if (!$input instanceof stdClass) throw new InvalidArgumentException('An enrichment catalog is required.');
            return (object) $this->catalog->publish($input, $this->user->getId());
        } catch (InvalidArgumentException | JsonException | \OverflowException | \Brick\Math\Exception\MathException $e) {
            throw new BadRequest($e->getMessage());
        }
    }

    public function getActionRead(Request $request, Response $response): object
    {
        $this->authorize($response);
        $query = $request->getQueryParams();
        if (array_diff(array_keys($query), ['policyId'])) throw new BadRequest('Unknown catalog query parameter.');
        if (array_key_exists('policyId', $query) && !is_string($query['policyId'])) {
            throw new BadRequest('Invalid enrichment catalog reference.');
        }
        try {
            $card = isset($query['policyId']) ? $this->catalog->resolve($query['policyId']) : $this->catalog->current();
            $receipt = $card->receipt();
            $receipt['inactiveRateIndexes'] = array_keys(array_filter($receipt['rates'], static fn (array $rate): bool =>
                $rate['provider'] === 'registrobr' || ($rate['provider'] === 'hub' && $rate['product'] === 'socios')));
            return (object) $receipt;
        } catch (InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }
    }

    private function authorize(Response $response): void
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        if (!$this->user->isAdmin()) throw new Forbidden('Instance administrator required for enrichment configuration.');
    }
}
