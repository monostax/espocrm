<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeatureClinica\Hooks\Orcamento;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * When an Orcamento is approved, auto-generates a Jornada (with Sessoes
 * from OrcamentoItems) and a LancamentoFinanceiro. Implemented as
 * beforeSave to ensure atomicity.
 */
class ApproveGenerateJornada
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isAttributeChanged('status')) {
            return;
        }

        if ($entity->get('status') !== 'Aprovado') {
            return;
        }

        if ($entity->get('jornadaId')) {
            return;
        }

        $pacienteId = $entity->get('pacienteId');
        $unidadeId = $entity->get('unidadeId');

        if (!$pacienteId || !$unidadeId) {
            return;
        }

        $teamsIds = $entity->getLinkMultipleIdList('teams');

        $jornada = $this->createJornada($entity, $teamsIds);
        $this->createSessoes($entity, $jornada, $teamsIds);
        $lancamento = $this->createLancamento($entity, $teamsIds);

        $entity->set('jornadaId', $jornada->getId());
        $entity->set('lancamentoId', $lancamento->getId());
    }

    private function createJornada(Entity $orcamento, array $teamsIds): Entity
    {
        $jornada = $this->entityManager->getNewEntity('Jornada');
        $data = [
            'pacienteId' => $orcamento->get('pacienteId'),
            'unidadeId' => $orcamento->get('unidadeId'),
            'convenioId' => $orcamento->get('convenioId'),
            'nome' => 'Orçamento #' . $orcamento->get('numero'),
            'dataInicio' => date('Y-m-d'),
            'status' => 'EmAndamento',
        ];

        $programaId = $orcamento->get('programaId');
        if ($programaId) {
            $data['programaId'] = $programaId;
        }

        $jornada->set($data);

        if (!empty($teamsIds)) {
            $jornada->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($jornada);

        return $jornada;
    }

    private function createSessoes(Entity $orcamento, Entity $jornada, array $teamsIds): void
    {
        $itens = $this->entityManager
            ->getRDBRepository('OrcamentoItem')
            ->where(['orcamentoId' => $orcamento->getId()])
            ->order('createdAt', 'ASC')
            ->find();

        $sequencia = 1;

        foreach ($itens as $item) {
            $quantidade = (int) $item->get('quantidade');
            if ($quantidade < 1) {
                $quantidade = 1;
            }

            for ($i = 0; $i < $quantidade; $i++) {
                $sessao = $this->entityManager->getNewEntity('Sessao');
                $sessao->set([
                    'jornadaId' => $jornada->getId(),
                    'procedimentoType' => $item->get('procedimentoType'),
                    'procedimentoId' => $item->get('procedimentoId'),
                    'sequencia' => $sequencia,
                    'status' => 'Pendente',
                    'unidadeId' => $orcamento->get('unidadeId'),
                ]);

                if (!empty($teamsIds)) {
                    $sessao->set('teamsIds', $teamsIds);
                }

                $this->entityManager->saveEntity($sessao);
                $sequencia++;
            }
        }
    }

    private function createLancamento(Entity $orcamento, array $teamsIds): Entity
    {
        $lancamento = $this->entityManager->getNewEntity('LancamentoFinanceiro');
        $lancamento->set([
            'pacienteId' => $orcamento->get('pacienteId'),
            'unidadeId' => $orcamento->get('unidadeId'),
            'convenioId' => $orcamento->get('convenioId'),
            'origemType' => 'Orcamento',
            'origemId' => $orcamento->getId(),
            'tipo' => 'Receita',
            'valorTotal' => $orcamento->get('valorTotal'),
            'valorDesconto' => $orcamento->get('valorDesconto'),
            'valorLiquido' => $orcamento->get('valorLiquido'),
            'status' => 'Pendente',
            'dataVencimento' => date('Y-m-d'),
            'formaPagamento' => 'Pix',
        ]);

        $autorizadoPorId = $orcamento->get('autorizadoPorId');
        if ($autorizadoPorId) {
            $lancamento->set('autorizadoPorId', $autorizadoPorId);
        }

        if (!empty($teamsIds)) {
            $lancamento->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($lancamento);

        return $lancamento;
    }
}
