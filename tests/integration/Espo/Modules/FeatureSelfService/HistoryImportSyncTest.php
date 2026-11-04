<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Jobs\SyncConversationsFromChatwoot;
use Espo\Modules\Chatwoot\Jobs\SyncInboxesFromChatwoot;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Meta delivers a coexistence history in phases, with past activity dates the
 * incremental conversation sync skips. The inbox sync mirrors the import and
 * asks for a backfill; the conversation sync then reads every conversation.
 */
class HistoryImportSyncTest extends TestCase
{
    private Application $app;
    private EntityManager $em;
    private string $fake;

    protected function setUp(): void
    {
        $database = getenv('SELF_SERVICE_TEST_DATABASE');
        $this->fake = (string) getenv('FAKE_CHATWOOT_URL');

        if (!$database || $this->fake === '') {
            self::markTestSkipped('Set SELF_SERVICE_TEST_DATABASE and FAKE_CHATWOOT_URL in an isolated CRM runtime.');
        }

        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        self::assertSame($database, $this->app->getContainer()->get('config')->get('database.dbname'));
        $this->app->setupSystemUser();
        $this->em = $this->app->getContainer()->get('entityManager');

        putenv(SelfServicePolicy::ENV . '=true');
        $this->app->getInjectableFactory()->create(SelfServicePolicy::class)->resume();

        if (!$this->em->getRDBRepository('ChatwootPlatform')->findOne()) {
            $this->em->createEntity('ChatwootPlatform', [
                'name' => 'Fake Chatwoot', 'backendUrl' => $this->fake, 'frontendUrl' => $this->fake, 'accessToken' => 'platform-token',
            ], ['skipHooks' => true]);
        }
    }

    public function testAHistoryPhaseBackfillsEveryConversation(): void
    {
        $account = $this->account();
        $chatwootId = (int) $account->get('chatwootAccountId');

        $this->seed($chatwootId, ['inboxes' => [$this->inbox(['status' => 'importing', 'phase' => 1, 'progress' => 40])]]);
        $this->syncInboxes($account);

        $inbox = $this->em->getRDBRepository('ChatwootInbox')->where(['chatwootAccountId' => $account->getId()])->findOne();
        self::assertSame('importing', $inbox->get('historyImportStatus'));
        self::assertSame(1, $inbox->get('historyImportPhase'));
        self::assertSame(40, $inbox->get('historyImportProgress'));
        self::assertSame(1, $this->backfillPage($account), 'A new phase asks for a backfill.');

        $this->setBackfillPage($account, null);
        $this->seed($chatwootId, ['inboxes' => [$this->inbox(['status' => 'importing', 'phase' => 1, 'progress' => 70])]]);
        $this->syncInboxes($account);
        self::assertNull($this->backfillPage($account), 'Progress within a phase does not.');

        $this->seed($chatwootId, ['inboxes' => [$this->inbox(['status' => 'declined', 'error_code' => 2593109])]]);
        $this->syncInboxes($account);
        self::assertNull($this->backfillPage($account), 'Nothing arrives when the owner declined.');

        // 60 threads from months ago, behind a cursor that points at today.
        $this->seed($chatwootId, ['conversations' => array_map(fn (int $n) => $this->conversation($n), range(1, 60))]);
        $account->set('conversationSyncCursor', time());
        $this->em->saveEntity($account, ['silent' => true]);
        $this->setBackfillPage($account, 1);

        $this->app->getInjectableFactory()->create(SyncConversationsFromChatwoot::class)->run();

        self::assertSame(60, $this->em->getRDBRepository('ChatwootConversation')
            ->where(['chatwootAccountId' => $account->getId()])->count());
        self::assertNull($this->backfillPage($account), 'Done once the last page is read.');
        self::assertSame(
            (int) $account->get('conversationSyncCursor'),
            (int) $this->reload($account)->get('conversationSyncCursor'),
            'The backfill leaves the incremental cursor alone.'
        );
    }

    private function account(): Entity
    {
        $suffix = bin2hex(random_bytes(4));
        $owner = $this->em->createEntity('User', [
            'firstName' => 'Owner', 'lastName' => $suffix, 'emailAddress' => "history-{$suffix}@example.test",
            'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
        $provisioner = $this->app->getInjectableFactory()->create(Provisioner::class);
        $record = $provisioner->start(new ProvisioningRequest("signup:history-{$suffix}", (string) $owner->getId(), "History {$suffix}"));
        $done = $provisioner->run((string) $record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));

        $account = $this->em->getEntityById('ChatwootAccount', $done->get('chatwootAccountId'));

        if (!$account->get('apiKey')) {
            $account->set('apiKey', 'account-token');
            $this->em->saveEntity($account, ['skipHooks' => true]);
        }

        return $account;
    }

    /**
     * @param array<string, mixed> $historyImport
     * @return array<string, mixed>
     */
    private function inbox(array $historyImport): array
    {
        return [
            'id' => 501,
            'name' => 'WhatsApp',
            'channel_type' => 'Channel::Whatsapp',
            'phone_number' => '+5511988887777',
            'provider' => 'whatsapp_cloud',
            'history_import' => $historyImport,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversation(int $n): array
    {
        return [
            'id' => 9000 + $n,
            'inbox_id' => 501,
            'status' => 'open',
            'last_activity_at' => time() - (100 + $n) * 86400,
            'created_at' => time(),
            'meta' => ['sender' => [
                'id' => 7000 + $n,
                'name' => "Contact {$n}",
                'phone_number' => '+55119' . str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function seed(int $chatwootAccountId, array $data): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode(['accountId' => $chatwootAccountId] + $data),
        ]]);
        self::assertNotFalse(file_get_contents($this->fake . '/__seed', false, $context));
    }

    private function syncInboxes(Entity $account): void
    {
        $this->app->getInjectableFactory()->create(SyncInboxesFromChatwoot::class)
            ->syncAccountInboxes($this->reload($account));
    }

    private function backfillPage(Entity $account): ?int
    {
        return $this->reload($account)->get('conversationBackfillPage');
    }

    private function setBackfillPage(Entity $account, ?int $page): void
    {
        $this->em->getQueryExecutor()->execute(
            $this->em->getQueryBuilder()->update()->in('ChatwootAccount')
                ->set(['conversationBackfillPage' => $page])->where(['id' => $account->getId()])->build()
        );
    }

    private function reload(Entity $entity): Entity
    {
        return $this->em->getEntityById($entity->getEntityType(), $entity->getId());
    }
}
