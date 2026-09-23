<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ConversationEpisodeSync;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once dirname(__DIR__) . '/Support/EntityDouble.php';

class EpisodeActivityHistoryBackfillTest extends TestCase
{
    private ConversationEpisodeSync $sync;
    private EntityDouble $account;
    private array $rows;
    private array $episodes;
    private int $saves = 0;

    protected function setUp(): void
    {
        $this->account = new EntityDouble(['id' => 'account-a', 'platformId' => 'platform', 'chatwootAccountId' => 9, 'apiKey' => 'test-key']);
        $this->rows = [[
            'id' => 4369, 'revision' => 2, 'superseded_at' => null,
            'lifecycle_labels' => ['consultas', 'consultas'],
            'lifecycle_assignees' => ['AI', 'Agent, Jr.', 'AI'],
            'lifecycle_teams' => [],
        ]];
        $this->episodes = [4369 => new EntityDouble([
            'id' => 'episode', 'chatwootAccountId' => 'account-a', 'sourceRevision' => 2,
            'startedAt' => '2026-08-31 12:35:52', 'closedAt' => '2026-08-31 13:35:15',
            'sourceMessageCount' => 10, 'syncedMessageCount' => 10, 'transcriptComplete' => true,
            'lastSyncedAt' => '2026-09-17 18:01:36', 'teamsIds' => ['tenant-team'],
        ])];
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('ChatwootPlatform', 'platform')
            ->willReturn(new EntityDouble(['backendUrl' => 'https://example.invalid']));
        $repo = $this->createMock(RDBRepository::class);
        $em->method('getRDBRepository')->with('ChatwootConversationEpisode')->willReturn($repo);
        $repo->method('where')->willReturnCallback(function ($where) {
            self::assertSame('account-a', $where['chatwootAccountId']);
            $query = $this->createMock(RDBSelectBuilder::class);
            $query->expects(self::once())->method('forUpdate')->willReturnSelf();
            $query->method('findOne')->willReturn($this->episodes[$where['chatwootEpisodeId']] ?? null);
            return $query;
        });
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->method('run')->willReturnCallback(fn ($work) => $work());
        $em->method('getTransactionManager')->willReturn($transactions);
        $em->method('saveEntity')->willReturnCallback(function ($entity, $options) {
            self::assertSame($this->episodes[4369], $entity);
            self::assertSame(['silent' => true], $options);
            $this->saves++;
        });
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('episodeRequest')->willReturnCallback(function ($url, $key, $accountId, $path) {
            self::assertSame('https://example.invalid', $url);
            self::assertSame('test-key', $key);
            self::assertSame(9, $accountId);
            self::assertMatchesRegularExpression('/^\?after=\d+$/', $path, 'Backfill must read acknowledged episodes and never acknowledge source revisions.');
            return ['payload' => $this->rows];
        });
        $this->sync = new ConversationEpisodeSync($em, $api, $this->createMock(InjectableFactory::class), $this->createMock(Log::class));
    }

    public function testDryRunReportsMissingHistoryWithoutMutatingEpisode(): void
    {
        $before = clone $this->episodes[4369]->getValueMap();
        $stats = $this->sync->backfillActivityHistory($this->account);
        self::assertSame(1, $stats['wouldUpdate']);
        self::assertSame(0, $stats['updated']);
        self::assertSame(0, $this->saves);
        self::assertEquals($before, $this->episodes[4369]->getValueMap());
    }

    public function testHistoricalSummaryIsFilledWithoutChangingTranscriptOrBoundaryEvidence(): void
    {
        $episode = $this->episodes[4369];
        $before = (array) $episode->getValueMap();
        $stats = $this->sync->backfillActivityHistory($this->account, 100, true);
        self::assertSame(1, $stats['updated']);
        self::assertSame(4369, $stats['after']);
        self::assertFalse($stats['hasMore']);
        self::assertSame('consultas', $episode->get('lifecycleTags'));
        self::assertSame(['AI', 'Agent, Jr.'], $episode->get('lifecycleAssigneeNames'));
        self::assertSame('AI, Agent, Jr.', $episode->get('lifecycleAssignees'));
        self::assertSame([], $episode->get('lifecycleTeamNames'));
        self::assertSame('', $episode->get('lifecycleTeams'));
        foreach ($before as $field => $value) self::assertSame($value, $episode->get($field), $field);
        $repeat = $this->sync->backfillActivityHistory($this->account, 100, true);
        self::assertSame(1, $repeat['unchanged']);
        self::assertSame(0, $repeat['updated']);
        self::assertSame(1, $this->saves);
    }

    public function testOlderAndNewerLocalRevisionsAreDeferred(): void
    {
        foreach ([1, 3] as $revision) {
            $this->episodes[4369]->set('sourceRevision', $revision);
            $stats = $this->sync->backfillActivityHistory($this->account, 0, true);
            self::assertSame(1, $stats['deferred']);
            self::assertSame(0, $stats['updated']);
        }
        self::assertSame(0, $this->saves);
    }

    public function testMissingOrSupersededEpisodesAreNeverCreatedOrRestored(): void
    {
        $this->episodes = [];
        self::assertSame(1, $this->sync->backfillActivityHistory($this->account, 0, true)['deferred']);
        $this->rows[0]['superseded_at'] = '2026-09-23T00:00:00Z';
        self::assertSame(1, $this->sync->backfillActivityHistory($this->account, 0, true)['deferred']);
        self::assertSame(0, $this->saves);
    }

    public function testOldSourceDeploymentIsRejectedInsteadOfInventingEmptyHistory(): void
    {
        unset($this->rows[0]['lifecycle_assignees']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Deploy the Chatwoot activity-history payload');
        $this->sync->backfillActivityHistory($this->account, 0, true);
    }

    public function testPageCursorIncludesDeferredRowsAndSignalsContinuation(): void
    {
        $row = $this->rows[0];
        $this->rows = array_map(fn ($id) => array_replace($row, ['id' => $id]), range(101, 200));
        $stats = $this->sync->backfillActivityHistory($this->account, 100, true);
        self::assertSame(200, $stats['after']);
        self::assertTrue($stats['hasMore']);
        self::assertSame(100, $stats['scanned']);
        self::assertSame(100, $stats['deferred']);
        self::assertSame(0, $this->saves);
    }
}
