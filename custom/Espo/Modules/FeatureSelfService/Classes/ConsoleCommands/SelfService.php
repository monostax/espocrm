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
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\SystemUser;
use Espo\Modules\FeatureSelfService\Tools\Offboarding\Offboarder;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServiceUnavailable;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceAccess;
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
 *   php command.php self-service workspace --tenant=<tenantId> [--status=trial|active|frozen] [--end-trial]
 *       Show a workspace's plan, status and trial dates; set its status, or end its trial
 *       now (frozen on the next access run). Chatwoot is updated right away.
 *   php command.php self-service offboard --tenant=<tenantId> --confirm=<slug>
 *       Offboard a tenant: end its WhatsApp sessions and Chatwoot accounts and remove
 *       its records, teams and own users (billing history stays). Runs for up to a few
 *       minutes; the job continues it. Run again to retry a failed one.
 *   php command.php self-service offboarding --tenant=<tenantId>
 *       Show a tenant's offboarding.
 */
class SelfService implements Command
{
    public function __construct(
        private SelfServicePolicy $policy,
        private Provisioner $provisioner,
        private EntityManager $entityManager,
        private WorkspaceAccess $access,
        private Offboarder $offboarder,
        private SystemUser $systemUser,
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
                'workspace' => $this->workspace($params, $io),
                'offboard' => $this->offboard($params, $io),
                'offboarding' => $this->offboarding($params, $io),
                default => $this->fail($io, 'Unknown action. Use status, pause, resume, provision, run, workspace, offboard or offboarding.'),
            };
        } catch (SelfServiceUnavailable $e) {
            $this->fail($io, $e->getMessage() . ' Set CRM_SELF_SERVICE_ENABLED=true and resume to provision.');
        } catch (InvalidArgumentException | Conflict | BadRequest | NotFound $e) {
            $this->fail($io, match ($e->getMessage()) {
                'SLUG_MISMATCH' => 'Give the tenant slug as --confirm.',
                'OPEN_SUBSCRIPTION' => 'The tenant has an open subscription. Cancel it before offboarding.',
                default => $e->getMessage(),
            });
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

        foreach ([Offboarder::STATUS_PENDING, Offboarder::STATUS_RUNNING, Offboarder::STATUS_FAILED, Offboarder::STATUS_COMPLETED] as $status) {
            $count = $this->entityManager->getRDBRepository(Offboarder::ENTITY_TYPE)->where(['status' => $status])->count();
            $io->writeLine("Offboardings {$status}: {$count}");
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

    private function workspace(Params $params, IO $io): void
    {
        $tenant = $this->entityManager->getEntityById('Tenant', (string) $params->getOption('tenant'));

        if (!$tenant || !$tenant->get('plan')) {
            $this->fail($io, 'No self-service workspace with that tenant id.');

            return;
        }

        $status = $params->getOption('status');

        if ($status !== null) {
            if (!in_array($status, [WorkspaceAccess::STATUS_TRIAL, WorkspaceAccess::STATUS_ACTIVE, WorkspaceAccess::STATUS_FROZEN], true)) {
                $this->fail($io, 'Status must be trial, active or frozen.');

                return;
            }

            $this->access->setStatus($tenant, $status);
        }

        if ($params->hasFlag('endTrial')) {
            $tenant->set('trialEndsAt', gmdate('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($tenant, ['silent' => true]);
            $this->access->freezeEndedTrials();
        }

        if ($status !== null || $params->hasFlag('endTrial')) {
            $this->access->syncChatwoot();
            $tenant = $this->entityManager->getEntityById('Tenant', $tenant->getId());
        }

        $io->writeLine(sprintf(
            'Tenant %s: plan %s, %s, onboarding %s, trial %s to %s, chat access %s.',
            $tenant->getId(),
            $tenant->get('plan'),
            $tenant->get('subscriptionStatus'),
            $tenant->get('onboardingStep'),
            $tenant->get('trialStartsAt') ?? '-',
            $tenant->get('trialEndsAt') ?? '-',
            $tenant->get('chatwootAccess') ?: '-',
        ));
    }

    private function offboard(Params $params, IO $io): void
    {
        $record = $this->offboarder->start(
            (string) $params->getOption('tenant'),
            (string) $params->getOption('confirm'),
            $this->systemUser->getId(),
        );

        $this->reportOffboarding($io, $this->offboarder->run((string) $record->getId()) ?? $record);
    }

    private function offboarding(Params $params, IO $io): void
    {
        $record = $this->offboarder->findByTenant((string) $params->getOption('tenant'));

        if (!$record) {
            $this->fail($io, 'No offboarding for that tenant.');

            return;
        }

        $this->reportOffboarding($io, $record);
    }

    private function reportOffboarding(IO $io, Entity $record): void
    {
        $io->writeLine(sprintf(
            'Offboarding %s of %s (%s): %s at step %s.',
            $record->getId(),
            $record->get('tenantName'),
            $record->get('tenantSlug'),
            $record->get('status'),
            $record->get('step'),
        ));

        $state = json_decode((string) json_encode($record->get('state')), true) ?: [];

        foreach (['removed' => 'Removed', 'kept' => 'Kept'] as $key => $label) {
            $counts = $state[$key] ?? [];
            ksort($counts);

            if ($counts) {
                $io->writeLine($label . ': ' . implode(', ', array_map(fn ($type, $n) => "{$type} {$n}", array_keys($counts), $counts)));
            }
        }

        if ($record->get('status') === Offboarder::STATUS_FAILED) {
            $io->writeLine('Last error: ' . $record->get('lastError'));
            $io->setExitStatus(1);
        }
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
