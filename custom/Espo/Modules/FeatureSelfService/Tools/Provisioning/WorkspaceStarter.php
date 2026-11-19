<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureSelfService\Tools\Provisioning;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Chatwoot\Services\AiAgentProvisioning;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Preview\PreviewCarry;
use Espo\Modules\Global\Classes\Utils\FeatureVerticalChecker;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;
use Throwable;

/**
 * What a new workspace starts with so its first minutes show value (Self-Service PLG, "Next steps to launch" §7):
 *
 * - a default "Vendas" funnel whose Ganho and Perdido stages carry the Monostax default rule (gtm/06), so deals
 *   found in the imported history have somewhere to go;
 * - a silent AI agent: no inbox, so Chatwoot never assigns it a conversation and it never replies. It manages the
 *   default funnel, which the opportunity tracker (gtm/06) writes through; "Ative a IA" later only connects an inbox;
 * - Em dia switched on, so the first read can analyse the imported conversations;
 * - the feature packs the plan includes (campaigns, journeys, playbooks, calls, meetings): one plan with everything
 *   since 2026-11-11 (gtm/07), so nothing a trial uses disappears after paying.
 *
 * A signup that came from the monostax.ai site preview (/previa) starts with what the visitor saw there instead:
 * the funnel stages it proposed, and the agent's instructions and knowledge base (the agent stays silent).
 *
 * Each step finds what an earlier attempt made and finishes it, so the provisioning can rerun it.
 */
class WorkspaceStarter
{
    public const FUNNEL_NAME = 'Vendas';

    /** Feature packs (app.featureVerticals) every self-service workspace gets. */
    public const FEATURE_PACKS = ['campaigns', 'journeys', 'playbooks', 'calls', 'meetings'];

    /**
     * The default funnel's stages. Ganho and Perdido are the Monostax default rule (gtm/06 §"Decided 2026-11-10"),
     * written where the agent and the tracker both read them. Estacionada is a paused deal: still open, so it keeps
     * the 50% of Em Qualificação.
     */
    /**
     * Perdido for a funnel the site preview proposed: the default rule without the default stage names, which such a
     * funnel may not have.
     */
    private const PREVIEW_LOST_RULE = 'O cliente recusou explicitamente, desistiu, escolheu outro fornecedor ou cancelou de vez, ou a '
        . 'empresa confirmou que não pode atendê-lo. Silêncio sozinho não é Perdido. "Agora não", "vou pensar", '
        . '"qualquer coisa, retorno" ou "só pesquisando preço" continuam abertos.';

    private const STAGES = [
        [
            'name' => 'Em Qualificação',
            'order' => 1,
            'probability' => 50,
            'style' => 'default',
            'description' => 'Existe interesse na compra ou no agendamento, mas a empresa ainda não confirmou. Inclui dúvidas, '
                . 'pedido de preço, orçamento enviado, objeções e escolha de data ou horário em andamento.',
        ],
        [
            'name' => 'Estacionada',
            'order' => 2,
            'probability' => 50,
            'style' => 'warning',
            'description' => 'A oportunidade está em pausa, sem recusa: o cliente pediu para retomar mais tarde ("agora não", '
                . '"me procure no mês que vem") ou parou de responder depois das tentativas de contato. Continua aberta e '
                . 'volta para Em Qualificação quando a conversa é retomada.',
        ],
        [
            'name' => 'Ganho',
            'order' => 3,
            'probability' => 100,
            'style' => 'success',
            'description' => 'A empresa confirmou o agendamento ou a compra, com data e horário definidos quando se aplica e o '
                . 'pagamento antecipado feito quando exigido. O serviço pode acontecer depois. Interesse, pedido de preço ou '
                . 'escolha de horário sem a confirmação da empresa não bastam.',
        ],
        [
            'name' => 'Perdido',
            'order' => 4,
            'probability' => 0,
            'style' => 'danger',
            'description' => 'O cliente recusou explicitamente, desistiu, escolheu outro fornecedor ou cancelou de vez, ou a '
                . 'empresa confirmou que não pode atendê-lo. Silêncio sozinho não é Perdido. "Agora não", "vou pensar", '
                . '"qualquer coisa, retorno" ou "só pesquisando preço" continuam em qualificação ou vão para Estacionada.',
        ],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private InjectableFactory $factory,
        private ChatwootApiClient $apiClient,
        private Metadata $metadata,
        private FeatureVerticalChecker $verticals,
        private Log $log,
    ) {}

    /**
     * Switches on the plan's feature packs: each pack is its role on the tenant's base user team, as the
     * `feature-packs` console command does. Packs already on stay as they are.
     */
    public function ensureFeaturePacks(Entity $tenant): void
    {
        $team = $this->entityManager->getEntityById('Team', (string) $tenant->get('baseUserTeamId'));

        if (!$team) {
            throw new RuntimeException('Tenant ' . $tenant->getId() . ' has no base user team for its feature packs.');
        }

        $definitions = $this->metadata->get(['app', 'featureVerticals']) ?? [];
        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
            $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';
        $relation = $this->entityManager->getRDBRepository('Team')->getRelation($team, 'roles');

        foreach (array_diff(self::FEATURE_PACKS, $this->verticals->getTenantVerticals((string) $tenant->getId())) as $pack) {
            $staticId = $definitions[$pack]['roleStaticId'] ?? null;

            if (!is_string($staticId) || !($definitions[$pack]['pack'] ?? false)) {
                throw new RuntimeException("Feature pack '{$pack}' is not defined.");
            }

            $relation->relateById(FeatureVerticalChecker::roleIdFor($staticId, $toHash));
        }
    }

    /**
     * The workspace's funnel. A tenant that already has one keeps it; otherwise "Vendas" and its stages are created
     * together, so a retry never finds half of them. With a site preview, its stages replace the default ones.
     */
    public function ensureSalesFunnel(Entity $tenant, ?PreviewCarry $preview = null): Entity
    {
        $existing = $this->findFunnel((string) $tenant->getId());

        if ($existing) {
            return $existing;
        }

        $baseTeamId = (string) $tenant->get('baseUserTeamId')
            ?: throw new RuntimeException('Tenant ' . $tenant->getId() . ' has no base team.');

        return $this->entityManager->getTransactionManager()->run(function () use ($tenant, $baseTeamId, $preview): Entity {
            $funnel = $this->entityManager->createEntity('Funnel', [
                'name' => self::FUNNEL_NAME,
                'tenantId' => $tenant->getId(),
                'teamsIds' => [$baseTeamId],
                'isActive' => true,
                'isDefault' => true,
            ]);

            foreach ($preview ? self::previewStages($preview) : self::STAGES as $stage) {
                $this->entityManager->createEntity('OpportunityStage', [
                    ...$stage,
                    'funnelId' => $funnel->getId(),
                    'teamsIds' => [$baseTeamId],
                    'isActive' => true,
                ]);
            }

            return $funnel;
        });
    }

    /**
     * The workspace's AI agent, created once per provisioning (the operation id is derived from it, so a retry
     * resumes the same identity), with no inbox, managing the default funnel. Provisioning runs as the system user
     * (signup requests, the job, the console), which AiAgentProvisioning reads when it is made, so it is made here.
     */
    public function ensureAiAgent(Entity $account, Entity $funnel, string $provisioningId, string $workspaceName): Entity
    {
        $membership = $this->factory->create(AiAgentProvisioning::class)->create((string) $account->getId(), (object) [
            'name' => self::agentName($workspaceName),
            'instructions' => '',
            'operationId' => self::operationId($provisioningId),
        ]);

        // AI follow-ups message customers: the agent never schedules them on its own until a person turns that on.
        if ($membership->get('followupsEnabled')) {
            $membership->set('followupsEnabled', false);
            $this->entityManager->saveEntity($membership, ['silent' => true, 'skipAssignedUserApiKeyProvision' => true]);
        }

        $funnels = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->getRelation($membership, 'funnelsToManage');

        if (!$funnels->isRelatedById((string) $funnel->getId())) {
            $funnels->relateById((string) $funnel->getId());
        }

        return $membership;
    }

    /**
     * Gives the silent agent what the site preview built: its instructions, when it has none yet, and a knowledge
     * base category with the preview's articles, when it has no category yet. Both checks make a rerun finish what
     * an earlier attempt started without duplicating it. The agent still has no inbox, so it answers nobody.
     */
    public function applyPreview(Entity $membership, Entity $tenant, ?PreviewCarry $preview): void
    {
        if (!$preview) {
            return;
        }

        if ($preview->instructions !== '' && trim(strip_tags((string) $membership->get('aiPrompt'))) === '') {
            // Same encoding AiAgentProvisioning uses for the instructions it is given.
            $membership->set('aiPrompt', nl2br(htmlspecialchars($preview->instructions, ENT_QUOTES)));
            $this->entityManager->saveEntity($membership, ['silent' => true, 'skipAssignedUserApiKeyProvision' => true]);
        }

        if (!$preview->knowledge) {
            return;
        }

        $baseTeamId = (string) $tenant->get('baseUserTeamId')
            ?: throw new RuntimeException('Tenant ' . $tenant->getId() . ' has no base team.');
        $categories = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->getRelation($membership, 'knowledgeBaseCategories');
        $category = $categories->findOne();

        if (!$category) {
            $name = mb_substr('Sobre a ' . ($preview->companyName ?: 'empresa'), 0, 100);
            $category = $this->createIndexed('KnowledgeBaseCategory', [
                'name' => $name,
                'aiIndexEnabled' => true,
                'teamsIds' => [$baseTeamId],
            ], ['aiIndexEnabled' => false]);
            $categories->relate($category);
        }

        $hasArticles = $this->entityManager
            ->getRDBRepository('KnowledgeBaseCategory')
            ->getRelation($category, 'articles')
            ->findOne();

        if ($hasArticles) {
            return;
        }

        foreach ($preview->knowledge as $article) {
            $this->createIndexed('KnowledgeBaseArticle', [
                'name' => $article['title'],
                'body' => $article['body'],
                'bodyFormat' => 'Markdown',
                'status' => 'Published',
                'categoriesIds' => [(string) $category->getId()],
                'teamsIds' => [$baseTeamId],
            ]);
        }
    }

    /**
     * Stages for a funnel the site preview proposed. Ganho and Perdido are told apart by probability (100 and 0),
     * as everywhere else, and keep the Monostax default rule as their description: the agent and the tracker read
     * it. Open stages spread between 10% and 90% in their order.
     *
     * @return list<array{name: string, order: int, probability: int, style: string, description: string}>
     */
    public static function previewStages(PreviewCarry $preview): array
    {
        $open = $preview->openStages ?: [['name' => self::STAGES[0]['name'], 'description' => self::STAGES[0]['description']]];
        $count = count($open);
        $stages = [];

        foreach ($open as $i => $stage) {
            $stages[] = [
                'name' => $stage['name'],
                'order' => $i + 1,
                'probability' => $count === 1 ? 50 : (int) round(10 + 80 * $i / ($count - 1)),
                'style' => 'default',
                'description' => $stage['description'],
            ];
        }

        $stages[] = ['name' => $preview->won['name'], 'order' => $count + 1, 'probability' => 100, 'style' => 'success', 'description' => self::STAGES[2]['description']];
        $stages[] = ['name' => $preview->lost['name'], 'order' => $count + 2, 'probability' => 0, 'style' => 'danger', 'description' => self::PREVIEW_LOST_RULE];

        return $stages;
    }

    /**
     * Creates a knowledge base record with its hooks, so it is indexed for the agent's search (a category's store is
     * made with Google on save). When that fails, the record is still created, silently and with `$fallback` applied,
     * so the owner finds the content and can turn indexing on later.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $fallback
     */
    private function createIndexed(string $entityType, array $values, array $fallback = []): Entity
    {
        try {
            return $this->entityManager->createEntity($entityType, $values);
        } catch (Throwable $e) {
            $this->log->warning("Self-service preview: {$entityType} saved without indexing: " . $e->getMessage());

            return $this->entityManager->createEntity($entityType, [...$values, ...$fallback], ['silent' => true]);
        }
    }

    /** Em dia on for the workspace's Chatwoot account. Setting it again is harmless. */
    public function ensureEmDia(Entity $account): void
    {
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));

        if (!$platform?->get('backendUrl') || !$account->get('apiKey') || (int) $account->get('chatwootAccountId') <= 0) {
            throw new RuntimeException('ChatwootAccount ' . $account->getId() . ' is not ready for its settings.');
        }

        $this->apiClient->updateAccountSettings(
            (string) $platform->get('backendUrl'),
            (string) $account->get('apiKey'),
            (int) $account->get('chatwootAccountId'),
            ['catch_up_enabled' => true],
        );
    }

    public static function agentName(string $workspaceName): string
    {
        return mb_substr('IA ' . trim($workspaceName), 0, 100);
    }

    /**
     * A UUID v4-shaped operation id that only this provisioning produces: AiAgentProvisioning accepts v4 ids, and
     * the same id makes a retry resume the reserved identity instead of reserving another.
     */
    public static function operationId(string $provisioningId): string
    {
        $hex = substr(hash('sha256', 'self-service-ai-agent|' . $provisioningId), 0, 32);
        $hex[12] = '4';
        $hex[16] = '89ab'[hexdec($hex[16]) & 3];

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private function findFunnel(string $tenantId): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('Funnel')
            ->where(['tenantId' => $tenantId])
            ->order('isDefault', 'DESC')
            ->order('createdAt')
            ->findOne();
    }
}
