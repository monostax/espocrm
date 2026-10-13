<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\{ReversalInput, Reversals};
use InvalidArgumentException;
use JsonException;
use stdClass;

final class CreditReversals
{
    public function __construct(private User $user, private Reversals $reversals) {}

    public function postActionReverse(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        if (!$this->user->isAdmin()) throw new Forbidden('Instance administrator required for credit reversals.');
        $raw = $request->getBodyContents() ?? '';
        if (strlen($raw) > 4096) throw new BadRequest('Reversal command is too large.');
        try {
            $body = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
            $fields = ['tenantId', 'transactionId', 'idempotencyKey', 'credits', 'reason'];
            if (!$body instanceof stdClass || array_diff(array_keys(get_object_vars($body)), $fields)) {
                throw new InvalidArgumentException('Invalid reversal command.');
            }
            foreach ($fields as $field) {
                if (!isset($body->$field) || !is_string($body->$field)) throw new InvalidArgumentException('Reversal fields must be strings.');
            }
            return (object) $this->reversals->reverse(new ReversalInput($body->tenantId, $body->transactionId,
                $body->idempotencyKey, $body->credits, $body->reason, $this->user->getId()));
        } catch (InvalidArgumentException | JsonException | \OverflowException | \Brick\Math\Exception\MathException $e) {
            throw new BadRequest($e->getMessage());
        }
    }
}
