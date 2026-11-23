<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Api;

use Espo\Core\Acl;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Controllers\OpportunityConversations;
use Espo\ORM\EntityManager;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Request-local enrichment, inside the live-query fingerprint middleware. */
final class OpportunityFollowup implements MiddlewareInterface
{
    public function __construct(
        private OpportunityConversations $conversations,
        private EntityManager $em,
        private Acl $acl,
        private Log $log,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $path = preg_replace('~^/api/v1~', '', $request->getUri()->getPath());
        if ($response->getStatusCode() !== 200 ||
            !preg_match('~^/(?:Opportunity(?:/[a-zA-Z0-9_-]+)?|(?:ChatwootConversation|Contact|Account)/[a-zA-Z0-9_-]+/opportunities)$~D', $path)) return $response;
        $accountId = filter_var($request->getQueryParams()['chatwootAccountId'] ?? null, FILTER_VALIDATE_INT);
        if (!$accountId || $accountId < 1) return $response;
        $data = json_decode((string) $response->getBody());
        $rows = $data->list ?? (isset($data->id) ? [$data] : []);
        if (!$rows) return $response;
        $records = [];
        foreach ($this->em->getRDBRepository('Opportunity')->where([
            'id' => array_map(fn ($row) => $row->id, $rows),
        ])->find() as $record) $records[$record->getId()] = $record;
        $funnelIds = [];
        foreach ($records as $record) {
            if ($record->get('followupBreakoutLimit') === null && $record->get('funnelId')) {
                $funnelIds[$record->get('funnelId')] = true;
            }
        }
        $funnelLimits = [];
        if ($funnelIds) {
            foreach ($this->em->getRDBRepository('Funnel')->where([
                'id' => array_keys($funnelIds),
            ])->find() as $funnel) {
                $funnelLimits[$funnel->getId()] = $funnel->get('followupBreakoutLimit');
            }
        }
        $eligible = [];
        try {
            $eligible = $this->conversations->whatsappEligibility($records, $accountId);
        } catch (\Throwable $error) {
            $this->log->warning('Opportunity follow-up eligibility unavailable: {message}', ['message' => $error->getMessage()]);
        }
        foreach ($rows as $row) {
            $row->hasAccessibleWhatsappConversation = $eligible[$row->id] ?? false;
            $record = $records[$row->id] ?? null;
            if ($record && $this->acl->checkField('Opportunity', 'followupCount')) {
                $row->followupCount = (int) ($record->get('followupCount') ?? 0);
            }
            if ($record && $this->acl->checkField('Opportunity', 'followupTimer')) {
                $row->followupTimer = $record->get('followupTimer');
            }
            if ($record && $this->acl->checkField('Opportunity', 'followupBreakoutLimit')) {
                $funnelId = $record->get('funnelId');
                $limit = $record->get('followupBreakoutLimit')
                    ?? ($funnelId ? ($funnelLimits[$funnelId] ?? null) : null)
                    ?? 1;
                $row->followupEffectiveLimit = max(1, (int) $limit);
            }
            $row->canEdit = $record && $this->acl->checkEntityEdit($record);
            $row->followupEditableFields = [];
            foreach (['followupTimer', 'followupBreakoutLimit', 'followupAiAgent'] as $field) {
                if ($field === 'followupAiAgent' && !$this->acl->checkScope('ChatwootAccountUserMembership', 'read')) continue;
                if ($row->canEdit && $this->acl->checkField('Opportunity', $field) &&
                    $this->acl->checkField('Opportunity', $field, 'edit')) $row->followupEditableFields[] = $field;
            }
        }
        return $response->withBody(Utils::streamFor(json_encode($data, JSON_THROW_ON_ERROR)))
            ->withoutHeader('Content-Length')->withHeader('Cache-Control', 'private, no-store');
    }
}
