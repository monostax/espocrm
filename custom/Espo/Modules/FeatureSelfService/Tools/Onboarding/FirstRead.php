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

namespace Espo\Modules\FeatureSelfService\Tools\Onboarding;

use DateInterval;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Modules\FeatureCatchUp\Services\Prefetch;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\Modules\FeatureSelfService\Jobs\RunFirstRead;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceTrial;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The first read (Self-Service PLG, "Next steps to launch" §7): once a new workspace's onboarding opens (its newest
 * history is in), Em dia analyses the first cards of the owner's deck, so the deck already has cards when they
 * arrive. It runs as the owner, through Em dia's own feeds and analysis (Prefetch), on the trial's credits.
 *
 * It waits for the CRM to have the imported conversations: the conversation backfill a history phase asks for must
 * be done, and at least one conversation synced. It never waits longer than MAX_WAIT_SECONDS; then it reads what is
 * there. Running it again analyses only what is still unjudged.
 *
 * The job never sleeps in a worker slot: it queues the analyses, then comes back every POLL_SECONDS (at most
 * POLL_ROUNDS times) to store the ones that completed. The console waits in place instead.
 */
class FirstRead
{
    /** Cards analysed at most, like the deck's own cap per open. */
    public const LIMIT = 15;
    /**
     * Right when onboarding opens (decided 2026-11-12: start the analysis as soon as possible). The CRM copies the
     * newest conversations within a minute, often before the import's first window ends; until then it looks again
     * every RECHECK_SECONDS.
     */
    public const FIRST_DELAY_SECONDS = 0;
    private const RECHECK_SECONDS = 15;
    private const MAX_WAIT_SECONDS = 900;
    /** Analyses take about 9 s (p50); stored every POLL_SECONDS for up to 3 minutes. */
    private const POLL_SECONDS = 10;
    private const POLL_ROUNDS = 18;
    /** The console's wait for the analyses it queued. */
    private const CONSOLE_WAIT_SECONDS = 150;

    public function __construct(
        private EntityManager $entityManager,
        private JobSchedulerFactory $jobs,
        private ActorScope $actors,
    ) {}

    /**
     * @param list<array{string, string}> $poll analyses queued by an earlier run, to store once complete
     */
    public function schedule(string $tenantId, int $delaySeconds = self::FIRST_DELAY_SECONDS, int $attempt = 0, array $poll = []): void
    {
        $this->jobs->create()
            ->setClassName(RunFirstRead::class)
            ->setData(['tenantId' => $tenantId, 'attempt' => $attempt, 'poll' => $poll])
            ->setDelay(new DateInterval('PT' . max(0, $delaySeconds) . 'S'))
            ->schedule();
    }

    /**
     * @param bool $wait The job: reschedule while the CRM is still receiving the conversations, and poll the queued
     *     analyses in later runs. False (the console): read what is there now and wait for the analyses in place.
     * @param list<array{string, string}> $poll A polling run: the analyses an earlier run queued.
     * @return ?array{picked: int, cards: int, quiet: int, pending: int, failed: int, waiting: list<array{string, string}>}
     *     null when there is nothing to read yet (rescheduled) or nothing to read at all
     */
    public function run(string $tenantId, int $attempt = 0, bool $wait = true, array $poll = []): ?array
    {
        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);

        if (!$tenant || !$tenant->get('plan') || $tenant->get('onboardingStep') !== WorkspaceTrial::STEP_DONE) {
            return null;
        }

        $account = $this->account($tenantId);
        $ownerId = $this->ownerId($tenantId);

        if (!$account || !$ownerId) {
            return null;
        }

        if (!$poll && $wait && !$this->conversationsSynced($account) && ($attempt + 1) * self::RECHECK_SECONDS < self::MAX_WAIT_SECONDS) {
            $this->schedule($tenantId, self::RECHECK_SECONDS, $attempt + 1);

            return null;
        }

        $locale = $tenant->get('language') === 'pt_BR' ? 'pt-BR' : 'en';
        $timeZone = (string) ($tenant->get('timeZone') ?: 'America/Sao_Paulo');
        $chatwootAccountId = (int) $account->get('chatwootAccountId');

        $result = $this->actors->run($ownerId, fn (callable $make) => $poll
            ? $make(Prefetch::class)->poll($chatwootAccountId, $timeZone, $locale, $poll)
            : $make(Prefetch::class)->run($chatwootAccountId, $timeZone, $locale, self::LIMIT, $wait ? 0 : self::CONSOLE_WAIT_SECONDS));

        $round = $poll ? $attempt + 1 : 0;
        if ($wait && $result['waiting'] && $round < self::POLL_ROUNDS) {
            $this->schedule($tenantId, self::POLL_SECONDS, $round, $result['waiting']);
        }

        return $result;
    }

    /**
     * The CRM has some of the imported conversations. The older pages may still be copying (backfill): the first read
     * starts with the newest ones rather than wait for the whole history, and the deck reads the rest as it opens.
     */
    private function conversationsSynced(Entity $account): bool
    {
        return $this->entityManager
            ->getRDBRepository('ChatwootConversation')
            ->where(['chatwootAccountId' => $account->getId()])
            ->findOne() !== null;
    }

    private function account(string $tenantId): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where(['tenantId' => $tenantId, 'status' => 'active', 'chatwootAccountId>' => 0])
            ->order('createdAt')
            ->findOne();
    }

    /** Whoever signed the workspace up: the provisioning's owner. */
    private function ownerId(string $tenantId): ?string
    {
        $provisioning = $this->entityManager
            ->getRDBRepository(Provisioner::ENTITY_TYPE)
            ->where(['tenantId' => $tenantId])
            ->order('createdAt')
            ->findOne();

        return $provisioning?->get('ownerId') ? (string) $provisioning->get('ownerId') : null;
    }
}
