<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\Chatwoot\Tools\Billing\AiBudget as Budget;

/** Internal worker endpoint. Never accepts a browser/agent-controlled billing outcome. */
final class AiBudget
{
    public function __construct(private Budget $budget) {}

    public function postActionExecute(Request $request): object
    {
        $secret = getenv('AI_BUDGET_SECRET');
        $raw = $request->getBodyContents() ?? '';
        $timestamp = $request->getHeader('X-Ai-Budget-Timestamp') ?? '';
        $signature = $request->getHeader('X-Ai-Budget-Signature') ?? '';
        if (!$secret || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 ||
            !hash_equals('sha256=' . hash_hmac('sha256', "ai-budget-v1.$timestamp.$raw", $secret), $signature)) {
            throw new Forbidden('Invalid AI budget signature.');
        }
        $input = json_decode($raw);
        if (!$input instanceof \stdClass) throw new BadRequest('Invalid budget request.');
        return $this->budget->execute($input);
    }
}
