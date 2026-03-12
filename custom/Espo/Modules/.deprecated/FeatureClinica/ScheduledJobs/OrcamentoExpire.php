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

namespace Espo\Modules\FeatureClinica\ScheduledJobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Daily job that expires Orcamentos past their validity date.
 */
class OrcamentoExpire implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function run(): void
    {
        $today = date('Y-m-d');

        $orcamentos = $this->entityManager
            ->getRDBRepository('Orcamento')
            ->where([
                'status' => 'Enviado',
                'dataValidade<' => $today,
            ])
            ->find();

        $count = 0;

        foreach ($orcamentos as $orcamento) {
            $orcamento->set('status', 'Expirado');
            $this->entityManager->saveEntity($orcamento);

            $numero = $orcamento->get('numero');
            $this->log->info("OrcamentoExpire: Orcamento #{$numero} expirado automaticamente.");

            $count++;
        }

        $this->log->info("OrcamentoExpire: Completed. {$count} orcamento(s) expired.");
    }
}
