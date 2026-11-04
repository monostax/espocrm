<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Enrichment\BatchApplication;
use Espo\Modules\FeatureCredits\Enrichment\BatchInput;
use Espo\Modules\FeatureCredits\Enrichment\Batches;
use Espo\Modules\FeatureCredits\Enrichment\EnrichmentEmails;
use Espo\Modules\FeatureCredits\Enrichment\EnrichmentStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BulkEnrichmentTest extends TestCase
{
    private const ID = 'aaaaaaaaaaaaaaaaa';

    public function testPersonalEmailsPutNameMatchesFirstAndDropSharedInboxes(): void
    {
        // Like mergeEnrichmentChannels, the first spelling of a duplicate address is kept.
        $this->assertSame(['JOAO.SILVA@acme.com.br', 'jsilva@acme.com', 'outra@acme.com'], EnrichmentEmails::personal([
            'contato@acme.com.br', 'outra@acme.com', 'JOAO.SILVA@acme.com.br', 'joao.silva@acme.com.br',
            'jsilva@acme.com', 'vendas@acme.com', 'recursos.humanos@acme.com',
        ], 'João da Silva'));
        // A shared-looking address that carries the person's name is personal.
        $this->assertSame(['contato.joao@acme.com'], EnrichmentEmails::personal(['contato.joao@acme.com'], 'Joao Souza'));
        $this->assertSame([], EnrichmentEmails::personal(['noreply@acme.com', ' '], ''));
    }

    public function testStatusOutcomesOnlyRecordCompletedSearches(): void
    {
        $this->assertSame('found', EnrichmentStatus::fieldOutcome(['no_match', 'matched']));
        $this->assertSame('found', EnrichmentStatus::fieldOutcome(['reused']));
        $this->assertSame('not_found', EnrichmentStatus::fieldOutcome(['skipped', 'no_match']));
        $this->assertNull(EnrichmentStatus::fieldOutcome(['failed', 'waived', 'skipped']));
        $this->assertNull(EnrichmentStatus::fieldOutcome([]));
        $this->assertSame('found', EnrichmentStatus::discoveryOutcome(2, 'budget'));
        $this->assertSame('not_found', EnrichmentStatus::discoveryOutcome(0, 'route_exhausted'));
        $this->assertSame('not_found', EnrichmentStatus::discoveryOutcome(0, 'page_limit'));
        foreach ([null, 'deadline', 'provider_failed', 'authorization_changed', 'ambiguous', 'unavailable'] as $reason) {
            $this->assertNull(EnrichmentStatus::discoveryOutcome(0, $reason), (string) $reason);
        }
    }

    public function testBatchInputBuildsSharedQuoteSettings(): void
    {
        $contacts = new BatchInput((object) ['tenantId' => self::ID, 'targetType' => 'Contact', 'product' => 'combined',
            'targetIds' => [self::ID, self::ID, 'bbbbbbbbbbbbbbbbb'], 'sources' => [3, 1]]);
        $this->assertSame([self::ID, 'bbbbbbbbbbbbbbbbb'], $contacts->targetIds);
        $this->assertSame(['products' => ['email', 'phone'], 'refresh' => true, 'sources' => [1, 3]], $contacts->settings);
        $accounts = new BatchInput((object) ['tenantId' => self::ID, 'targetType' => 'Account', 'product' => 'socios',
            'targetIds' => [self::ID], 'requestKey' => 'run-1'], BatchInput::MAX_RECORDS, true);
        $this->assertSame(['products' => ['socios'], 'refresh' => true, 'count' => 10], $accounts->settings);
        $this->assertSame(['products' => ['socios'], 'refresh' => true, 'count' => 10, 'tenantId' => self::ID,
            'targetType' => 'Account', 'targetId' => 'x'], BatchInput::base(self::ID, 'Account', 'x', $accounts->settings));
    }

    public static function invalidInputs(): iterable
    {
        $ok = ['tenantId' => self::ID, 'targetType' => 'Contact', 'product' => 'email', 'targetIds' => [self::ID], 'sources' => [1]];
        yield 'partners on contacts' => [['product' => 'socios'] + $ok];
        yield 'email on accounts' => [['targetType' => 'Account'] + $ok];
        yield 'no sources' => [array_diff_key($ok, ['sources' => true])];
        yield 'unknown source' => [['sources' => [9]] + $ok];
        yield 'count on email' => [['count' => 3] + $ok];
        yield 'no records' => [['targetIds' => []] + $ok];
        yield 'too many records' => [['targetIds' => array_fill(0, 51, self::ID)] + $ok];
        yield 'bad id' => [['targetIds' => ['x;drop']] + $ok];
        yield 'unexpected key' => [['requestKey' => 'k'] + $ok];
        yield 'partner limit' => [['targetType' => 'Account', 'product' => 'socios', 'count' => 51] + array_diff_key($ok, ['sources' => true])];
    }

    #[DataProvider('invalidInputs')]
    public function testBatchInputRejects(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BatchInput((object) $input, BatchInput::MAX_PREVIEW);
    }

    public function testPartnerContactDataUsesReviewDefaultsOrAsksForReview(): void
    {
        $form = ['available' => true, 'teamsAllowed' => true, 'teamsRequired' => false, 'teams' => [['id' => 't1', 'name' => 'A']],
            'fields' => [['name' => 'lastName', 'required' => true], ['name' => 'dateOfBirth', 'required' => false]]];
        $application = ['available' => true, 'creationForm' => $form];
        $this->assertSame(['lastName' => 'Maria Souza', 'dateOfBirth' => '1980-02-03', 'teamsIds' => ['t1']],
            BatchApplication::contactData(['name' => ' Maria Souza ', 'dateOfBirth' => '1980-02-03'], $application));
        $this->assertNull(BatchApplication::contactData(['name' => ''], $application));
        $required = $form; $required['fields'][] = ['name' => 'cargo', 'required' => true];
        $this->assertNull(BatchApplication::contactData(['name' => 'Maria'], ['available' => true, 'creationForm' => $required]));
        $teams = $form; $teams['teams'][] = ['id' => 't2', 'name' => 'B']; $teams['teamsRequired'] = true;
        $this->assertNull(BatchApplication::contactData(['name' => 'Maria'], ['available' => true, 'creationForm' => $teams]));
        $teams['teamsRequired'] = false;
        $this->assertSame(['lastName' => 'Maria'], BatchApplication::contactData(['name' => 'Maria'], ['available' => true, 'creationForm' => $teams]));
        $this->assertNull(BatchApplication::contactData(['name' => 'Maria'], ['available' => false, 'creationForm' => $form]));
    }

    public function testProjectionSumsCountsWithoutPurchasedValues(): void
    {
        $batch = ['id' => 'b', 'tenant_id' => 't', 'target_type' => 'Contact', 'product' => 'email', 'state' => 'running',
            'pause_reason' => null, 'total' => '4', 'created_at' => '2026-11-04 10:00:00', 'completed_at' => null];
        $items = [
            ['target_id' => 'c1', 'state' => 'done', 'reason' => null, 'credits' => '3.0000',
                'outcome' => '{"emailsFound":2,"emailsAdded":1,"phonesFound":0,"phonesAdded":0,"needsReview":0}'],
            ['target_id' => 'c2', 'state' => 'done', 'reason' => null, 'credits' => '0.0000',
                'outcome' => '{"emailsFound":0,"emailsAdded":0,"phonesFound":0,"phonesAdded":0,"needsReview":0}'],
            ['target_id' => 'c3', 'state' => 'skipped', 'reason' => 'no_route', 'credits' => null, 'outcome' => null],
            ['target_id' => 'c4', 'state' => 'done', 'reason' => null, 'credits' => '8.0000', 'outcome' => '{"emailsFound":1,"needsReview":1}'],
        ];
        $result = Batches::project($batch, $items);
        $this->assertSame(['pending' => 0, 'running' => 0, 'done' => 3, 'skipped' => 1, 'no_credits' => 0, 'failed' => 0, 'cancelled' => 0],
            $result['counts']);
        $this->assertSame('11.0000', $result['credits']);
        $this->assertSame(2, $result['recordsFound']);
        $this->assertSame(['no_route' => 1], (array) $result['skipped']);
        $this->assertSame(['emailsFound' => 3, 'emailsAdded' => 1, 'phonesFound' => 0, 'phonesAdded' => 0, 'needsReview' => 1],
            (array) $result['totals']);
        $this->assertSame(['c4'], $result['reviewTargetIds']);
        $this->assertSame(4, $result['total']);
    }
}
