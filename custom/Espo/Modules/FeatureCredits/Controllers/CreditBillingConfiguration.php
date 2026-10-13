<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Billing\Catalog;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** Instance-admin commercial publication; never a payment confirmation or tenant purchase API. */
final class CreditBillingConfiguration
{
    public function __construct(private User $user, private Catalog $catalog) {}

    public function postActionPublish(Request $request, Response $response): object
    {
        $this->authorize($response);
        $raw = $request->getBodyContents() ?? '';
        if (strlen($raw) > 65536) throw new BadRequest('Billing catalog is too large.');
        try {
            $input = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            if (!$input instanceof stdClass) throw new InvalidArgumentException('A billing catalog is required.');
            return (object) $this->catalog->publish($input, $this->user->getId());
        } catch (InvalidArgumentException | JsonException | \OverflowException | \Brick\Math\Exception\MathException $e) {
            throw new BadRequest($e->getMessage());
        }
    }

    public function getActionRead(Request $request, Response $response): object
    {
        $this->authorize($response);
        $query = $request->getQueryParams();
        if (array_diff(array_keys($query), ['catalogId'])) throw new BadRequest('Unknown billing catalog query parameter.');
        if (array_key_exists('catalogId', $query) && !is_string($query['catalogId'])) {
            throw new BadRequest('Invalid billing catalog reference.');
        }
        try {
            $catalog = isset($query['catalogId']) ? $this->catalog->resolve($query['catalogId']) : $this->catalog->current();
            return (object) $catalog->receipt();
        } catch (InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }
    }

    private function authorize(Response $response): void
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        if (!$this->user->isAdmin()) throw new Forbidden('Instance administrator required for billing configuration.');
    }
}
