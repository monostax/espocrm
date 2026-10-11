<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Services\Configuration;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** Commercial terms can be published only by an instance administrator, not by
 * a tenant member granting their own discounts. Existing versions stay immutable. */
final class CreditConfiguration
{
    public function __construct(private User $user, private Configuration $configuration) {}

    public function postActionPublish(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        if (!$this->user->isAdmin()) throw new Forbidden('Instance administrator required to publish credit terms.');
        $raw = $request->getBodyContents() ?? '';
        if (strlen($raw) > 16384) throw new BadRequest('Configuration is too large.');
        try {
            $input = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
            if (!$input instanceof stdClass || array_diff(array_keys(get_object_vars($input)), ['operation', 'data']) ||
                !(($input->data ?? null) instanceof stdClass)) throw new InvalidArgumentException('A configuration command is required.');
            $replacesId = null;
            if (($input->operation ?? null) === 'replaceAgreement') {
                $replacesId = $input->data->replacesId ?? null;
                if (!is_string($replacesId)) throw new InvalidArgumentException('The replaced agreement ID is required.');
                unset($input->data->replacesId);
            }
            $result = match ($input->operation ?? null) {
                'publishModel' => $this->configuration->publishModel($input->data, $this->user->getId()),
                'publishAgreement' => $this->configuration->publishAgreement($input->data, $this->user->getId()),
                'replaceAgreement' => $this->configuration->publishAgreement($input->data, $this->user->getId(), $replacesId),
                default => throw new InvalidArgumentException('Unknown configuration operation.'),
            };
            return (object) $result;
        } catch (InvalidArgumentException | JsonException | \OverflowException | \Brick\Math\Exception\MathException $e) {
            throw new BadRequest($e->getMessage());
        }
    }
}
