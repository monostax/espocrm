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
use Espo\Modules\Chatwoot\Services\AiAgentProvisioning;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;

/**
 * What a new workspace starts with so its first minutes show value (Self-Service PLG, "Next steps to launch" §7):
 *
 * - a default "Vendas" funnel whose Ganho and Perdido stages carry the Monostax default rule (gtm/06), so deals
 *   found in the imported history have somewhere to go;
 * - a silent AI agent: no inbox, so Chatwoot never assigns it a conversation and it never replies. It manages the
 *   default funnel, which the opportunity tracker (gtm/06) writes through; "Ative a IA" later only connects an inbox;
 * - Em dia switched on, so the first read can analyse the imported conversations.
 *
 * Each step finds what an earlier attempt made and finishes it, so the provisioning can rerun it.
 */
class WorkspaceStarter
{
    public const FUNNEL_NAME = 'Vendas';

    /**
     * The default funnel's stages. Ganho and Perdido are the Monostax default rule (gtm/06 §"Decided 2026-11-10"),
     * written where the agent and the tracker both read them.
     */
    private const STAGES = [
        [
            'name' => 'Em negociação',
            'order' => 1,
            'probability' => 50,
            'style' => 'default',
            'description' => 'Existe interesse na compra ou no agendamento, mas a empresa ainda não confirmou. Inclui dúvidas, '
                . 'pedido de preço, orçamento enviado, objeções e escolha de data ou horário em andamento.',
        ],
        [
            'name' => 'Ganho',
            'order' => 2,
            'probability' => 100,
            'style' => 'success',
            'description' => 'A empresa confirmou o agendamento ou a compra, com data e horário definidos quando se aplica e o '
                . 'pagamento antecipado feito quando exigido. O serviço pode acontecer depois. Interesse, pedido de preço ou '
                . 'escolha de horário sem a confirmação da empresa não bastam.',
        ],
        [
            'name' => 'Perdido',
            'order' => 3,
            'probability' => 0,
            'style' => 'danger',
            'description' => 'O cliente recusou explicitamente, desistiu, escolheu outro fornecedor ou cancelou de vez, ou a '
                . 'empresa confirmou que não pode atendê-lo. Silêncio sozinho não é Perdido. "Agora não", "vou pensar", '
                . '"qualquer coisa, retorno" ou "só pesquisando preço" continuam em negociação.',
        ],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private InjectableFactory $factory,
        private ChatwootApiClient $apiClient,
    ) {}

    /**
     * The workspace's funnel. A tenant that already has one keeps it; otherwise "Vendas" and its stages are created
     * together, so a retry never finds half of them.
     */
    public function ensureSalesFunnel(Entity $tenant): Entity
    {
        $existing = $this->findFunnel((string) $tenant->getId());

        if ($existing) {
            return $existing;
        }

        $baseTeamId = (string) $tenant->get('baseUserTeamId')
            ?: throw new RuntimeException('Tenant ' . $tenant->getId() . ' has no base team.');

        return $this->entityManager->getTransactionManager()->run(function () use ($tenant, $baseTeamId): Entity {
            $funnel = $this->entityManager->createEntity('Funnel', [
                'name' => self::FUNNEL_NAME,
                'tenantId' => $tenant->getId(),
                'teamsIds' => [$baseTeamId],
                'isActive' => true,
                'isDefault' => true,
                // AI follow-ups message customers: nothing in a trial does that before "Ative a IA".
                'followupsEnabled' => false,
            ]);

            foreach (self::STAGES as $stage) {
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

        $funnels = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->getRelation($membership, 'funnelsToManage');

        if (!$funnels->isRelatedById((string) $funnel->getId())) {
            $funnels->relateById((string) $funnel->getId());
        }

        return $membership;
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
