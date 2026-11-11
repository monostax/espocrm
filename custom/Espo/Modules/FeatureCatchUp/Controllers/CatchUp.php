<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCatchUp\Services\Actions;
use Espo\Modules\FeatureCatchUp\Services\Agenda;
use Espo\Modules\FeatureCatchUp\Services\Batch;
use Espo\Modules\FeatureCatchUp\Services\ConversationRecord;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\ProposedDeals;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Snoozes;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCatchUp\Services\Workspace;

/** Operation API; deliberately exposes no generic entity CRUD. */
class CatchUp
{
    public function __construct(
        private Feed $feed,
        private Summary $summary,
        private Actions $actions,
        private Snoozes $snoozes,
        private Workspace $workspace,
        private ServiceFeed $service,
        private ConversationRecord $record,
        private Agenda $agenda,
        private ProposedDeals $proposedDeals,
        private Batch $batch,
    ) {}

    /** The Agenda tab: loops not due yet, pending next steps and armed AI follow-ups, from stored data only. */
    public function getActionAgenda(Request $request): object
    {
        return (object) $this->agenda->list($request);
    }

    /** The conversation sidebar: what Em dia already concluded about one Chatwoot conversation. Never analyses. */
    public function getActionRecord(Request $request): object
    {
        return (object) $this->record->read($request);
    }

    /** The deck's analyses, up to Batch::MAX_START subjects of either lane: snapshot plus stored verdict or queued analysis. */
    public function postActionBatch(Request $request): object
    {
        return (object) $this->batch->start($request);
    }

    /** Where the deck's queued analyses stand; only finished ones build their snapshot again and are stored. */
    public function postActionBatchStatus(Request $request): object
    {
        return (object) $this->batch->status($request);
    }

    public function getActionList(Request $request): object
    {
        return (object) $this->feed->list($request);
    }

    /**
     * The deck's totals: cards by tab (`us`: Esperando resposta, `customer`: Esperando follow-up, `found`: Negócios
     * encontrados), subjects whose settle analysis is still to come (`pending`), and the badge (`count`, every card).
     */
    public function getActionCount(Request $request): object
    {
        $deals = $this->feed->count($request);
        ['tenant' => $tenant, 'account' => $account] = $this->workspace->resolve($request);
        $service = $this->service->count($tenant->getId(), $account);
        $cards = fn (array $counts) => $counts['us'] + $counts['customer'] + $counts['found'];
        return (object) [
            'count' => $cards($deals) + $cards($service),
            'us' => $deals['us'] + $service['us'], 'customer' => $deals['customer'] + $service['customer'],
            'found' => $deals['found'] + $service['found'], 'pending' => $deals['pending'] + $service['pending'],
            'deals' => $cards($deals), 'service' => $cards($service),
        ];
    }

    public function getActionServiceList(Request $request): object
    {
        return (object) $this->service->list($request);
    }

    public function getActionServiceRead(Request $request): object
    {
        return (object) $this->service->snapshot($this->id($request), $request);
    }

    public function postActionServiceSummary(Request $request): object
    {
        $snapshot = $this->service->snapshot($this->id($request), $request);
        return (object) [...$this->summary->analyse($snapshot, ...$this->summaryArguments($request)), 'snapshot' => $snapshot];
    }

    public function postActionServiceReview(Request $request): object
    {
        $this->service->review($this->id($request), (string) ($request->getParsedBody()->snapshot ?? ''), $request);
        return (object) ['success' => true];
    }

    public function postActionServiceResolved(Request $request): object
    {
        $this->service->resolved($this->id($request), $request);
        return (object) ['success' => true];
    }

    /** "Criar oportunidade" on an Atendimento card: the deal it found, as shown or as the reader changed it. */
    public function postActionServiceDeal(Request $request): object
    {
        return (object) $this->proposedDeals->create($request, $this->id($request));
    }

    public function postActionServiceLabel(Request $request): object
    {
        $body = $request->getParsedBody();
        return (object) $this->service->label($this->id($request), (string) ($body->contactId ?? ''), (string) ($body->class ?? ''),
            (string) ($body->snapshot ?? ''), $request);
    }

    public function getActionRead(Request $request): object
    {
        return (object) $this->feed->snapshot($this->id($request), $request);
    }

    public function postActionReview(Request $request): object
    {
        $this->feed->review($this->id($request), (string) ($request->getParsedBody()->snapshot ?? ''), $request);
        return (object) ['success' => true];
    }

    public function postActionSummary(Request $request): object
    {
        $arguments = $this->summaryArguments($request);
        $snapshot = $this->feed->snapshot($this->id($request), $request);
        return (object) [...$this->summary->analyse($snapshot, ...$arguments), 'snapshot' => $snapshot];
    }

    /** @return array{string, ?string} the analysis locale and the request id being polled */
    private function summaryArguments(Request $request): array
    {
        $body = $request->getParsedBody();
        $locale = $body->locale ?? 'en';
        $requestId = $body->requestId ?? null;
        if ($requestId !== null && (!is_string($requestId) || !preg_match('/^[a-f0-9]{64}$/D', $requestId))) {
            throw new BadRequest('Invalid summary request ID.');
        }
        return [Summary::locale($locale), $requestId];
    }

    public function postActionClose(Request $request): object
    {
        return (object) $this->actions->close($request, $this->id($request));
    }

    public function postActionResolvedElsewhere(Request $request): object
    {
        return (object) $this->actions->resolvedElsewhere($request, $this->id($request));
    }

    public function postActionSnooze(Request $request): object
    {
        $body = $request->getParsedBody();
        $tenant = $this->workspace->resolve($request)['tenant'];
        return (object) $this->snoozes->snooze($tenant, (string) ($body->type ?? ''), (string) ($body->id ?? ''), (string) ($body->until ?? ''));
    }

    private function id(Request $request): string
    {
        return (string) $request->getRouteParam('id');
    }
}
