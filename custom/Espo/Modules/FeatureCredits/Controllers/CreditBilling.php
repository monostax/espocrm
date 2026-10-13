<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Billing\{Accounts, Creation};
use InvalidArgumentException;
use stdClass;

final class CreditBilling
{
    public function __construct(private User $user, private Access $access, private Accounts $accounts, private Creation $creation) {}

    public function getActionContext(Request $request, Response $response): object
    {
        return $this->execute($response, function () use ($request): array {
            $input = $this->validate($request->getQueryParams(), ['tenantId']);
            $this->access->assertTenant($input['tenantId']);
            return $this->accounts->context($input['tenantId']);
        });
    }

    public function getActionObligation(Request $request, Response $response): object
    {
        return $this->execute($response, function () use ($request): array {
            $input = $this->validate($request->getQueryParams(), ['tenantId', 'obligationId']);
            $this->access->assertTenant($input['tenantId']);
            return $this->accounts->readObligation($input['tenantId'], $input['obligationId']);
        });
    }

    public function postActionCountry(Request $request, Response $response): object
    {
        return $this->execute($response, function () use ($request): array {
            $input = $this->body($request, ['tenantId', 'requestKey', 'revision', 'billingCountry']);
            $this->access->assertTenant($input['tenantId']);
            return $this->accounts->changeCountry($input['tenantId'], $input['requestKey'], $input['revision'], $input['billingCountry'], $this->user->getId());
        });
    }

    public function postActionOverride(Request $request, Response $response): object
    {
        return $this->execute($response, function () use ($request): array {
            if (!$this->user->isAdmin()) throw new Forbidden('Only instance administrators can change billing provider overrides.');
            $input = $this->body($request, ['tenantId', 'requestKey', 'revision', 'providerOverride']);
            $this->access->assertTenant($input['tenantId']);
            return $this->accounts->changeOverride($input['tenantId'], $input['requestKey'], $input['revision'], $input['providerOverride'], $this->user->getId());
        });
    }

    public function postActionAccept(Request $request, Response $response): object
    {
        return $this->execute($response, function () use ($request): array {
            $input = $this->body($request, ['tenantId', 'requestKey', 'revision', 'catalogId', 'offerId', 'termsId']);
            $this->access->assertTenant($input['tenantId']);
            return $this->accounts->accept($input['tenantId'], $input['requestKey'], $input['revision'],
                $input['catalogId'], $input['offerId'], $input['termsId'], $this->user->getId());
        });
    }

    public function postActionCancel(Request $request, Response $response): object
    {
        return $this->execute($response, function () use ($request): array {
            $input = $this->body($request, ['tenantId', 'requestKey', 'obligationId']);
            $this->access->assertTenant($input['tenantId']);
            return $this->accounts->cancel($input['tenantId'], $input['requestKey'], $input['obligationId'], $this->user->getId());
        });
    }

    public function postActionCustomer(Request $request, Response $response): object
    {
        return $this->execute($response, function () use ($request): array {
            $input = $this->body($request, ['tenantId', 'obligationId', 'payer']);
            $this->access->assertTenant($input['tenantId']);
            return $this->creation->customer($input['tenantId'], $input['obligationId'], get_object_vars($input['payer']));
        });
    }

    public function postActionCharge(Request $request, Response $response): object
    {
        return $this->execute($response, function () use ($request): array {
            $input = $this->body($request, ['tenantId', 'obligationId', 'customerCreationId', 'billingType', 'dueDate']);
            $this->access->assertTenant($input['tenantId']);
            return $this->creation->charge($input['tenantId'], $input['obligationId'], $input['customerCreationId'], $input['billingType'], $input['dueDate']);
        });
    }

    public function postActionRecoverCreation(Request $request, Response $response): object
    {
        return $this->execute($response, function () use ($request): array {
            $input = $this->body($request, ['tenantId', 'creationId']);
            $this->access->assertTenant($input['tenantId']);
            return $this->creation->reconcile($input['tenantId'], $input['creationId']);
        });
    }

    private function execute(Response $response, callable $action): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        try { return (object) $action(); }
        catch (InvalidArgumentException | \JsonException | \OverflowException | \Brick\Math\Exception\MathException $e) { throw new BadRequest($e->getMessage()); }
    }

    private function body(Request $request, array $keys): array
    {
        $raw = $request->getBodyContents() ?? '';
        if (strlen($raw) > 4096) throw new BadRequest('Billing command is too large.');
        $body = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
        if (!$body instanceof stdClass) throw new BadRequest('A billing command is required.');
        return $this->validate(get_object_vars($body), $keys);
    }

    private function validate(array $input, array $keys): array
    {
        if (array_diff(array_keys($input), $keys) || array_diff($keys, array_keys($input))) throw new BadRequest('Invalid billing command fields.');
        foreach ($input as $key => $value) {
            if ($key === 'payer') {
                if (!$value instanceof stdClass) throw new BadRequest('A payer object is required.');
            } elseif ($key === 'revision') {
                if (!is_int($value)) throw new BadRequest('Billing revision must be an integer.');
            } elseif (!(in_array($key, ['billingCountry', 'providerOverride'], true) && $value === null) && !is_string($value)) {
                throw new BadRequest('Invalid billing command value.');
            }
        }
        GrantInput::identity($input['tenantId']);
        return $input;
    }
}
