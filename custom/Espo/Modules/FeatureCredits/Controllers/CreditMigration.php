<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\LegacyMigration;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class CreditMigration
{
    public function __construct(private User $user, private LegacyMigration $migration) {}

    public function postActionGrant(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        if (!$this->user->isAdmin()) throw new Forbidden('Instance administrator required for migration grants.');
        $raw = $request->getBodyContents() ?? '';
        if (strlen($raw) > 4096) throw new BadRequest('Migration command is too large.');
        try {
            $body = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
            $fields = ['tenantId', 'cutoverId', 'credits', 'expiresAt', 'legacyReference', 'approvalReference'];
            if (!$body instanceof stdClass || array_diff(array_keys(get_object_vars($body)), $fields)) {
                throw new InvalidArgumentException('Invalid migration command.');
            }
            foreach ($fields as $field) {
                if (!property_exists($body, $field) || (!is_string($body->$field) && !($field === 'expiresAt' && $body->$field === null))) {
                    throw new InvalidArgumentException('Migration requires explicit terms, including nullable expiration.');
                }
            }
            return (object) $this->migration->grant($body->tenantId, $body->cutoverId, $body->credits,
                $body->expiresAt, $body->legacyReference, $body->approvalReference, $this->user->getId());
        } catch (InvalidArgumentException | JsonException | \OverflowException | \Brick\Math\Exception\MathException $e) {
            throw new BadRequest($e->getMessage());
        }
    }
}
