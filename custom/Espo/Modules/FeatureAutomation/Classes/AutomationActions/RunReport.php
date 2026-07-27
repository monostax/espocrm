<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureAutomation\Services\CrossTenantAccess;
use Espo\Modules\FeatureAutomation\Services\PayloadBag;
use Espo\Modules\FeatureAutomation\Services\ReportPayloadBuilder;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\ORM\EntityManager;

/**
 * Run an Advanced List/Grid Report and store the result on item payload.
 *
 * params:
 *  - reportId (required)
 *  - as (payload path, default "report")
 *  - mode: auto|list|grid
 *  - maxRows (list)
 *  - period: none|currentDay|previousDay|currentWeek|previousWeek|currentMonth|previousMonth
 *  - periodField: date attribute for runtime period filter
 *  - scopeAiAgentConversations: when true, period windows ChatwootAiAgentRun.runAt and
 *    the report is filtered to rows whose id is an Opportunity linked (via
 *    ChatwootConversation) to a conversation with ≥1 AI run in that window.
 *    periodField on the report entity is ignored in this mode.
 *  - timezone: IANA tz for period bounds
 *  - where: extra Espo where items (array)
 *  Tenant filter is ALWAYS applied when ctx.tenantId is set (e.g. forEach Tenant).
 *  Fails closed when tenant is unresolved unless an instance-admin `crossTenant`
 *  automation is authorized (see CrossTenantAccess) — only then may the report
 *  run unscoped. params can never opt out of an explicit tenantId.
 */
class RunReport implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private ReportPayloadBuilder $reportPayloadBuilder,
        private PayloadBag $payloadBag,
        private CrossTenantAccess $crossTenantAccess,
    ) {}

    public function run(ActionContext $ctx): void
    {
        $params = $ctx->params;
        $as = trim((string) ($params['as'] ?? 'report'));
        if ($as === '') {
            $as = 'report';
        }

        // ctx->journey carries the Automation entity for automation-triggered actions.
        $crossTenant = $ctx->actor !== null
            && $this->crossTenantAccess->isAuthorized($ctx->journey, $ctx->actor);

        $blob = $this->reportPayloadBuilder->build(
            $params,
            $ctx->tenantId,
            $ctx->actor,
            $crossTenant,
        );

        $record = $ctx->record;
        if (!$record) {
            throw new Error('runReport: missing run item record.');
        }

        $payload = $this->payloadBag->normalize($record->get('payload'));
        $payload = $this->payloadBag->setByPath($payload, $as, $blob, false);

        $record->set('payload', $payload);
        if ($record->hasId() && $record->getId() && !str_starts_with((string) $record->getId(), 'sim_')) {
            $this->entityManager->saveEntity($record, [SaveOption::SKIP_ALL => true]);
        }
    }
}
