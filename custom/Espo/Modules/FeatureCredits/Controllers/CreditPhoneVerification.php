<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Brick\Math\Exception\MathException;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredits\Verification\CustomerProjection;
use Espo\Modules\FeatureCredits\Verification\Operations;
use Espo\Modules\FeatureCredits\Verification\Kind;
use InvalidArgumentException;
use JsonException;
use OverflowException;
use stdClass;

/**
 * Authenticated customer endpoints for phone identity verification (D10, D23, Section 5).
 * Registered with CustomerResponse middleware for private/no-store and sanitized errors.
 */
class CreditPhoneVerification
{
    protected const KIND = Kind::PHONE;
    public function __construct(private Operations $operations) {}

    public function postActionContext(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $input = $this->parseJsonBody($request);
        try {
            return (object) CustomerProjection::context($this->operations->context($input, static::KIND));
        } catch (InvalidArgumentException | JsonException | OverflowException | MathException $e) {
            throw new BadRequest($e->getMessage());
        }
    }

    public function postActionStart(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $input = $this->parseJsonBody($request);
        try {
            return (object) CustomerProjection::status($this->operations->start($input, static::KIND));
        } catch (InvalidArgumentException | JsonException | OverflowException | MathException $e) {
            throw new BadRequest($e->getMessage());
        }
    }

    public function getActionStatus(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        try {
            return (object) CustomerProjection::status($this->operations->status($request->getQueryParams(), static::KIND));
        } catch (InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }
    }

    public function getActionHistory(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        try {
            return (object) CustomerProjection::history($this->operations->history($request->getQueryParams(), static::KIND));
        } catch (InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }
    }

    private function parseJsonBody(Request $request): stdClass
    {
        $raw = $request->getBodyContents() ?? '';
        if (strlen($raw) > 16384) {
            throw new BadRequest('Phone verification command is too large.');
        }
        try {
            $input = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
            if (!$input instanceof stdClass) {
                throw new InvalidArgumentException('Phone verification command object is required.');
            }
            return $input;
        } catch (InvalidArgumentException | JsonException $e) {
            throw new BadRequest($e->getMessage());
        }
    }
}
