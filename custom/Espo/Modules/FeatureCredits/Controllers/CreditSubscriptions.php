<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\BadRequest;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Billing\Subscriptions;

final class CreditSubscriptions
{
    public function __construct(private User $user, private Access $access, private Subscriptions $subscriptions) {}

    public function postActionCancel(Request $request, Response $response): object { return $this->execute($request, $response, true); }
    public function postActionReconcile(Request $request, Response $response): object { return $this->execute($request, $response, false); }

    private function execute(Request $request, Response $response, bool $cancel): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        try {
            $raw = $request->getBodyContents() ?? '';
            if (strlen($raw) > 4096) throw new BadRequest('Subscription command is too large.');
            $body = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
            if (!$body instanceof \stdClass) throw new BadRequest('Subscription command required.');
            $input = get_object_vars($body); $keys = $cancel ? ['tenantId', 'obligationId', 'requestKey'] : ['tenantId', 'obligationId'];
            if (array_diff(array_keys($input), $keys) || array_diff($keys, array_keys($input))) throw new BadRequest('Invalid subscription command fields.');
            foreach ($input as $value) if (!is_string($value)) throw new BadRequest('Invalid subscription command value.');
            $this->access->assertTenant($input['tenantId']);
            return (object) ($cancel ? $this->subscriptions->cancel($input['tenantId'], $input['obligationId'], $input['requestKey'], $this->user->getId()) :
                $this->subscriptions->reconcile($input['tenantId'], $input['obligationId']));
        } catch (\InvalidArgumentException | \JsonException $e) { throw new BadRequest($e->getMessage()); }
    }
}
