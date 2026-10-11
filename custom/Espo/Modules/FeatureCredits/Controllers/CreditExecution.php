<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureCredits\Accounting\ExecutionRouting;
use Espo\Modules\FeatureCredits\Accounting\AuthorizationInput;
use Espo\Modules\FeatureCredits\Accounting\AdmissionConfiguration;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\Modules\FeatureCredits\Accounting\Requests;
use Espo\Modules\FeatureCredits\Pricing\AiCatalog;
use Espo\Modules\FeatureCredits\Accounting\Outcomes;
use Espo\Modules\FeatureCredits\Accounting\Reservations;
use Espo\Modules\FeatureCredits\Accounting\Settlements;
use Espo\Modules\FeatureCredits\Accounting\TerminalInput;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** Signed execution bridge; browser sessions/API-user credentials grant no authority here. */
final class CreditExecution
{
    public function __construct(private Outcomes $outcomes, private Settlements $settlements, private Reservations $reservations,
        private AiCatalog $catalog, private Requests $requests, private AdmissionConfiguration $configuration) {}

    public function postActionExecute(Request $request): object
    {
        $secret = getenv('AI_CREDIT_EXECUTION_SECRET');
        $raw = $request->getBodyContents() ?? '';
        $timestamp = $request->getHeader('X-Credit-Execution-Timestamp') ?? '';
        $signature = $request->getHeader('X-Credit-Execution-Signature') ?? '';
        if (!$secret || !preg_match('/^[0-9]{1,11}$/D', $timestamp) || abs(time() - (int) $timestamp) > 300 ||
            !hash_equals('sha256=' . hash_hmac('sha256', "credit-execution-v1.$timestamp.$raw", $secret), $signature)) {
            throw new Forbidden('Invalid credit execution signature.');
        }
        if (strlen($raw) > 65536) throw new BadRequest('Credit execution command is too large.');
        try {
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            if (!$decoded instanceof stdClass) throw new InvalidArgumentException('A terminal command object is required.');
            if (($decoded->operation ?? null) === 'configuration') {
                return (object) ['billingRegime' => ExecutionRouting::UNIFIED, 'operation' => 'configuration',
                    'selection' => (object) $this->configuration->resolve($decoded)];
            }
            $input = in_array($decoded->operation ?? null, ['admit', 'authorize'], true) ?
                new AuthorizationInput($decoded) : new TerminalInput($decoded);
        } catch (InvalidArgumentException | JsonException $e) {
            throw new BadRequest($e->getMessage());
        }
        $execution = $input->execution;
        if ($input instanceof AuthorizationInput) {
            $policy = $this->catalog->resolve($input->modelRateId);
            if ($input->operation === 'admit') {
                $bound = $policy->request($input->tenantId, 'initial-bound', $execution->executionId, 'initial-bound')->credits;
                $receipt = $this->reservations->reserve(new ReservationInput($input->tenantId, 'ai', $execution->operationKey(),
                    $execution->executionId, $input->billingRateId, $bound, execution: $execution, modelPolicyId: $input->modelRateId));
            } else {
                $receipt = $this->requests->authorize($policy->request($input->tenantId, $input->usageId,
                    $execution->executionId, $input->requestKey), $execution);
            }
            return (object) ['billingRegime' => ExecutionRouting::UNIFIED, 'operation' => $input->operation,
                'receipt' => (object) $receipt, 'policy' => (object) $policy->receipt()];
        }
        $receipt = match ($input->operation) {
            'outcome' => $this->outcomes->record($input->outcome, $execution),
            'settle' => $this->settlements->settle($input->tenantId, $input->usageId, $execution->executionId, $execution),
            'release' => $this->reservations->release($input->tenantId, $input->usageId, $execution->executionId, $execution),
        };
        return (object) ['billingRegime' => ExecutionRouting::UNIFIED, 'operation' => $input->operation, 'receipt' => (object) $receipt];
    }
}
