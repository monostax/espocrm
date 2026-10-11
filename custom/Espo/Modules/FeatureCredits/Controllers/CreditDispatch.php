<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureCredits\Accounting\DispatchInput;
use Espo\Modules\FeatureCredits\Accounting\DispatchStore;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** Service-only CRM storage bridge. Uses a distinct signature domain. */
final class CreditDispatch
{
    public function __construct(private DispatchStore $store) {}

    public function postActionExecute(Request $request): object
    {
        $secret = getenv('AI_CREDIT_EXECUTION_SECRET');
        $raw = $request->getBodyContents() ?? '';
        $timestamp = $request->getHeader('X-Credit-Execution-Timestamp') ?? '';
        $signature = $request->getHeader('X-Credit-Execution-Signature') ?? '';
        if (!$secret || !preg_match('/^[0-9]{1,11}$/D', $timestamp) || abs(time() - (int) $timestamp) > 300 ||
            !hash_equals('sha256=' . hash_hmac('sha256', "credit-dispatch-v1.$timestamp.$raw", $secret), $signature)) {
            throw new Forbidden('Invalid credit dispatch signature.');
        }
        if (strlen($raw) > 65536) throw new BadRequest('Credit dispatch command is too large.');
        try {
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            if (!$decoded instanceof stdClass) throw new InvalidArgumentException('Dispatch object required.');
            $input = new DispatchInput($decoded);
        } catch (InvalidArgumentException | JsonException $e) { throw new BadRequest($e->getMessage()); }
        return (object) ['operation' => $input->data->operation, 'result' => $this->store->execute($input)];
    }
}
