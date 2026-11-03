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

namespace Espo\Modules\FeatureSelfService\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServiceUnavailable;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;

/**
 * Operate self-service from the CRM container.
 *
 *   php command.php self-service
 *       Show the switch, the pause and provisioning counts.
 *   php command.php self-service pause --reason="..."
 *   php command.php self-service resume
 *       Pause or resume new self-service work on every process at once.
 *   php command.php self-service provision --key=<key> --owner=<userId> --name="Acme" \
 *       [--language=pt_BR] [--time-zone=America/Sao_Paulo]
 *       Provision a workspace for an existing regular user, and run it.
 *   php command.php self-service run --id=<provisioningId>
 *       Run (or retry) one provisioning now.
 */
class SelfService implements Command
{
    public function __construct(
        private SelfServicePolicy $policy,
        private Provisioner $provisioner,
        private EntityManager $entityManager,
    ) {}

    public function run(Params $params, IO $io): void
    {
        try {
            match ($params->getArgument(0) ?? 'status') {
                'status' => $this->status($io),
                'pause' => $this->pause($params, $io),
                'resume' => $this->resume($io),
                'provision' => $this->provision($params, $io),
                'run' => $this->runOne($params, $io),
                default => $this->fail($io, 'Unknown action. Use status, pause, resume, provision or run.'),
            };
        } catch (SelfServiceUnavailable $e) {
            $this->fail($io, $e->getMessage() . ' Set CRM_SELF_SERVICE_ENABLED=true and resume to provision.');
        } catch (InvalidArgumentException | Conflict $e) {
            $this->fail($io, $e->getMessage());
        }
    }

    private function status(IO $io): void
    {
        $io->writeLine('Deployment switch (' . SelfServicePolicy::ENV . '): '
            . (SelfServicePolicy::isDeploymentEnabled() ? 'on' : 'off'));
        $io->writeLine('Runtime pause: ' . ($this->policy->isPaused()
            ? 'paused (' . ($this->policy->pauseReason() ?? 'no reason') . ')'
            : 'not paused'));
        $io->writeLine('Effective: ' . $this->policy->state());

        foreach ([
            Provisioner::STATUS_PENDING,
            Provisioner::STATUS_RUNNING,
            Provisioner::STATUS_PAUSED,
            Provisioner::STATUS_FAILED,
            Provisioner::STATUS_COMPLETED,
        ] as $status) {
            $count = $this->entityManager
                ->getRDBRepository(Provisioner::ENTITY_TYPE)
                ->where(['status' => $status])
                ->count();

            $io->writeLine("Provisionings {$status}: {$count}");
        }
    }

    private function pause(Params $params, IO $io): void
    {
        $reason = trim((string) $params->getOption('reason'));

        if ($reason === '') {
            $this->fail($io, 'Give a --reason for the pause.');

            return;
        }

        $this->policy->pause($reason);
        $io->writeLine('Self-service paused.');
    }

    private function resume(IO $io): void
    {
        $this->policy->resume();
        $io->writeLine('Self-service resumed. Effective: ' . $this->policy->state());
    }

    private function provision(Params $params, IO $io): void
    {
        $request = new ProvisioningRequest(
            (string) $params->getOption('key'),
            (string) $params->getOption('owner'),
            (string) $params->getOption('name'),
            $params->getOption('language') ?? 'pt_BR',
            $params->getOption('timeZone') ?? 'America/Sao_Paulo',
        );

        $record = $this->provisioner->start($request);
        $this->report($io, $this->provisioner->run((string) $record->getId()) ?? $record);
    }

    private function runOne(Params $params, IO $io): void
    {
        $id = (string) $params->getOption('id');
        $record = $this->provisioner->run($id);

        if (!$record) {
            $this->fail($io, "Provisioning {$id} not found.");

            return;
        }

        $this->report($io, $record);
    }

    private function report(IO $io, Entity $record): void
    {
        $io->writeLine(sprintf(
            'Provisioning %s: %s at step %s. Tenant %s, ChatwootAccount %s.',
            $record->getId(),
            $record->get('status'),
            $record->get('step'),
            $record->get('tenantId'),
            $record->get('chatwootAccountId'),
        ));

        if ($record->get('status') === Provisioner::STATUS_FAILED) {
            $io->writeLine('Last error: ' . $record->get('lastError'));
            $io->setExitStatus(1);
        }
    }

    private function fail(IO $io, string $message): void
    {
        $io->writeLine($message);
        $io->setExitStatus(1);
    }
}
