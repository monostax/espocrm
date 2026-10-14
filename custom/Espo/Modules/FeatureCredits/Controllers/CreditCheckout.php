<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Billing\Checkout;
use Espo\Modules\FeatureCredits\Billing\CustomerProjection;

final class CreditCheckout
{
    public function __construct(private Access $access, private Checkout $checkout) {}

    public function getActionContext(Request $request, Response $response): object
    { return $this->execute($request, $response, 'context'); }

    public function getActionRead(Request $request, Response $response): object
    { return $this->execute($request, $response, 'read'); }

    public function postActionPayment(Request $request, Response $response): object
    { return $this->execute($request, $response, 'payment'); }

    private function execute(Request $request, Response $response, string $action): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        try {
            $input = $request->getQueryParams();
            if ($action === 'payment') {
                $raw = $request->getBodyContents() ?? '';
                if (strlen($raw) > 4096) throw new BadRequest('Checkout command is too large.');
                $body = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
                if (!$body instanceof \stdClass) throw new BadRequest('A checkout command is required.');
                $input = get_object_vars($body);
            }
            $keys = $action === 'context' ? ['tenantId'] : ['tenantId', 'obligationId'];
            if (array_diff(array_keys($input), $keys) || array_diff($keys, array_keys($input))) throw new BadRequest('Invalid checkout fields.');
            foreach ($input as $value) {
                if (!is_string($value)) throw new BadRequest('Invalid checkout identity.');
                GrantInput::identity($value);
            }
            $this->access->assertTenant($input['tenantId']);
            return (object) match ($action) {
                'context' => CustomerProjection::context($this->checkout->context($input['tenantId'])),
                'read' => CustomerProjection::checkout($this->checkout->read($input['tenantId'], $input['obligationId'])),
                // M4 blocker: hosted payment handoff still discloses the processor.
                'payment' => $this->checkout->payment($input['tenantId'], $input['obligationId']),
            };
        } catch (\InvalidArgumentException | \JsonException $e) { throw new BadRequest($e->getMessage()); }
    }
}
