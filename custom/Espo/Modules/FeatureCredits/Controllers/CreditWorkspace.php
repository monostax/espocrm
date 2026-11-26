<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Conflict, Forbidden};
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\{AgreementModels, BalanceStatus, Clock, ExecutionRouting, GrantInput, UsageMeter};
use Espo\Modules\FeatureCredits\Pricing\AiCatalog;
use Espo\Modules\FeatureCredits\Accounting\{ConversationUsage, OperationHistory, OperationHistoryQuery};
use Espo\ORM\Entity;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;

/** Minimal operational wallet projection. Financial history/purchasing retain their own ACLs. */
class CreditWorkspace
{
    public function __construct(
        private User $user,
        private Acl $acl,
        private UserTenantResolver $tenants,
        private EntityManager $em,
        private Access $financialAccess,
        private BalanceStatus $balance,
        private Clock $clock,
    ) {}

    public function getActionRead(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $params = $request->getQueryParams();
        $accountId = $params['accountId'] ?? null;
        if (array_diff(array_keys($params), ['accountId']) || !is_string($accountId) ||
            !preg_match('/\A[1-9][0-9]{0,14}\z/', $accountId)) {
            throw new BadRequest('An explicit Chatwoot account is required.');
        }
        $account = $this->account($accountId);
        $tenantId = $account->get('tenantId');
        $ids = $this->financialAccess->tenantIds();
        $canManage = $ids === null || in_array($tenantId, $ids, true);
        $query = $this->em->getPDO()->prepare('SELECT cutover_at, deleted FROM tenant_credit_cutover WHERE tenant_id = ?');
        $query->execute([$tenantId]);
        $cutover = $query->fetch(\PDO::FETCH_ASSOC);
        if ($cutover) {
            if ($cutover['deleted']) throw new Conflict('Workspace cutover unavailable.');
            GrantInput::timestamp($cutover['cutover_at']);
        }
        $regime = $cutover && $cutover['cutover_at'] <= $this->clock->now() ? ExecutionRouting::UNIFIED : ExecutionRouting::LEGACY;
        $status = $this->balance->inspect($tenantId);
        // Customers see this period's usage as a share, not the balance (decided 2026-11-26).
        $usage = $regime === ExecutionRouting::UNIFIED && $status['walletExists'] ?
            (new UsageMeter($this->em, $this->clock))->inspect($tenantId, $status) : null;
        return (object) [
            'accountId' => $accountId,
            'tenantId' => $tenantId,
            'billingRegime' => $regime,
            'canManage' => $canManage,
            'availableCredits' => $status['availableCredits'],
            'reservedCredits' => $status['reservedCredits'],
            'usage' => $usage,
            'observedAt' => $status['observedAt'],
        ];
    }

    /**
     * Models any workspace user may pick per AI Session or mention, from the active
     * agreement. A legacy-billed workspace (or one without an agreement) gets none.
     */
    public function getActionModels(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $params = $request->getQueryParams();
        $accountId = $params['accountId'] ?? null;
        if (array_diff(array_keys($params), ['accountId']) || !is_string($accountId) ||
            !preg_match('/\A[1-9][0-9]{0,14}\z/', $accountId)) {
            throw new BadRequest('An explicit Chatwoot account is required.');
        }
        $tenantId = $this->account($accountId)->get('tenantId');
        $models = (new AgreementModels($this->em, $this->clock, new AiCatalog($this->em)))->forTenant($tenantId);
        return (object) [
            'accountId' => $accountId,
            'tenantId' => $tenantId,
            'default' => $models['default'] ?? null,
            'models' => $models['models'] ?? [],
        ];
    }

    /**
     * A conversation's AI use for its panel: its share of this period's credits (UsageMeter::period), how many AI
     * interactions it had and the latest ten, by what they were, without amounts (decided 2026-11-26). Only the
     * workspace's financial administrators read it; anyone else gets `canManage: false`, so attendants never see
     * what a reply costs. Exact amounts stay in the financial reports.
     */
    public function getActionConversation(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $params = $request->getQueryParams();
        if (array_diff(array_keys($params), ['accountId', 'conversationId'])) throw new BadRequest();
        foreach (['accountId', 'conversationId'] as $key) {
            if (!is_string($params[$key] ?? null) || !preg_match('/\A[1-9][0-9]{0,14}\z/', $params[$key])) throw new BadRequest();
        }
        $account = $this->account($params['accountId']);
        $tenantId = $account->get('tenantId');
        $ids = $this->financialAccess->tenantIds();
        if ($ids !== null && !in_array($tenantId, $ids, true)) {
            return (object) ['accountId' => $params['accountId'], 'conversationId' => $params['conversationId'], 'canManage' => false];
        }
        if (!$this->acl->checkScope('ChatwootConversation', 'read')) throw new Forbidden();
        $rows = iterator_to_array($this->em->getRDBRepository('ChatwootConversation')->where([
            'chatwootAccountId' => $account->getId(),
            'chatwootConversationId' => (int) $params['conversationId'],
        ])->limit(0, 2)->find());
        if (count($rows) !== 1) throw new Forbidden('Conversation unavailable.');
        $conversation = array_values($rows)[0];
        if ($conversation->get('chatwootAccountId') !== $account->getId() ||
            !$this->acl->check($conversation, 'read')) throw new Forbidden();
        $status = $this->balance->inspect($tenantId);
        $period = $status['walletExists'] ? (new UsageMeter($this->em, $this->clock))->period($tenantId, $status) : null;
        $usage = (new ConversationUsage($this->em))->inspect($tenantId, $conversation->getId(), $period['startedAt'] ?? null);
        $history = (new OperationHistory($this->em, $this->clock))->inspect(
            new OperationHistoryQuery($tenantId, 10), $conversation->getId(),
        );
        return (object) [
            'accountId' => $params['accountId'], 'conversationId' => $params['conversationId'], 'canManage' => true,
            'tenantId' => $history['tenantId'], 'observedAt' => $history['observedAt'],
            // The period's start and the share in it; both null for a workspace without a wallet period.
            'since' => $period['startedAt'] ?? null,
            'share' => $period === null ? null : ConversationUsage::share($usage['usedCredits'], $period['periodCredits']),
            'interactions' => $usage['interactions'],
            // An Em dia analysis starts from a queued request; anything else is a run of the AI agent.
            'list' => array_map(static fn (array $row): array => [
                'id' => $row['id'], 'state' => $row['state'] === 'admitted' ? 'pending' : $row['state'],
                'kind' => $row['sourceRequest'] ? 'analysis' : 'agent', 'admittedAt' => $row['admittedAt'],
            ], $history['list']),
        ];
    }

    private function account(string $accountId): Entity
    {
        if (!$this->user->getId() || $this->user->isPortal() || $this->user->isApi() || $this->user->isSystem() ||
            $this->user->get('deleted') || !$this->acl->checkScope('ChatwootAccount', 'read')) {
            throw new Forbidden('Workspace membership required.');
        }
        $accounts = $this->em->getRDBRepository('ChatwootAccount')->where(['chatwootAccountId' => (int) $accountId])->limit(0, 2)->find();
        $rows = iterator_to_array($accounts);
        if (count($rows) !== 1) throw new Forbidden('Workspace mapping unavailable.');
        $account = array_values($rows)[0];
        $tenantId = $account->get('tenantId');
        if (!is_string($tenantId) || !$this->acl->check($account, 'read') ||
            (!$this->user->isAdmin() && !$this->tenants->canActForTenant($this->user, $tenantId))) {
            throw new Forbidden('Workspace membership required.');
        }
        return $account;
    }
}
