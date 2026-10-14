<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Verification\CapabilityGate;
use Espo\Modules\FeatureCredits\Verification\Execution;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Operator-only administration for phone verification activation, allowlist, limits,
 * kill switch, and sanitized diagnostics (D9, D20, D25).
 */
final class CreditPhoneVerificationConfiguration
{
    public function __construct(
        private User $user,
        private CapabilityGate $capability,
        private Execution $execution,
    ) {}

    public function getActionRead(Request $request, Response $response): object
    {
        $this->authorize($response);
        if ($request->getQueryParams() !== []) {
            throw new BadRequest('Unknown phone verification configuration query parameter.');
        }
        return (object) [
            'configuration' => $this->capability->configuration(),
            'policyVersion' => $this->capability->policyVersion(),
            'diagnostics' => $this->execution->diagnostics(),
        ];
    }

    public function postActionPublish(Request $request, Response $response): object
    {
        $this->authorize($response);
        $raw = $request->getBodyContents() ?? '';
        if (strlen($raw) > 16384) {
            throw new BadRequest('Configuration is too large.');
        }
        try {
            $input = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
            if (!$input instanceof stdClass) {
                throw new InvalidArgumentException('A phone verification configuration object is required.');
            }
            return (object) [
                'configuration' => $this->capability->publish($input, (string) $this->user->getId()),
                'policyVersion' => $this->capability->policyVersion(),
            ];
        } catch (InvalidArgumentException | JsonException $e) {
            throw new BadRequest($e->getMessage());
        }
    }

    private function authorize(Response $response): void
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Instance administrator required for phone verification configuration.');
        }
    }
}
