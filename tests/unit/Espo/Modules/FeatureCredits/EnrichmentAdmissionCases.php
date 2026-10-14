<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\Modules\FeatureCredits\Accounting\Reservations;
use Espo\Modules\FeatureCredits\Accounting\WalletLock;
use Espo\Modules\FeatureCredits\Controllers\CreditEnrichmentQuotes;
use Espo\Modules\FeatureCredits\Enrichment\Catalog;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait EnrichmentAdmissionCases
{
    private function assertAdmissionAudit(PDO $pdo): void
    {
        $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createStub(Clock::class); $clock->method('now')->willReturn($this->now);
        $audit = new \Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit($manager, $clock);
        $this->assertSame([], $audit->inspect('tenant')['findings']);
        $operations = new \Espo\Modules\FeatureCredits\Accounting\OperationHistory($manager, $clock);
        $reservations = new \Espo\Modules\FeatureCredits\Accounting\ReservationHistory($manager, $clock);
        $ops = $operations->inspect(new \Espo\Modules\FeatureCredits\Accounting\OperationHistoryQuery('tenant'))['list'];
        $holds = $reservations->inspect(new \Espo\Modules\FeatureCredits\Accounting\ReservationHistoryQuery('tenant'))['list'];
        $this->assertCount(count($ops), $holds);
        $this->assertNotEmpty($ops);
    }

    private function fundAdmission(PDO $pdo, string $credits = '10', ?string $expires = null): Reservations
    {
        $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createStub(Clock::class); $clock->method('now')->willReturnCallback(fn () => $this->now);
        $lock = new WalletLock($manager, $clock);
        $funding = new Funding($lock);
        $funding->grant(new GrantInput('tenant', $expires === null ? 'purchase' : 'subscription', 'opening', $credits, $this->now, $expires, (object) ['fixture' => true]));
        $pdo->exec("INSERT INTO tenant_credit_billing_rate
            (id, tenant_id, version, effective_from, currency, credit_unit_price, monthly_credits, created_at)
            VALUES ('rate', 'tenant', 'v1', '2026-10-01 00:00:00', 'USD', '1.00000000', '0.0000', '2026-10-01 00:00:00')");
        return new Reservations($lock, $funding);
    }

    private function accepted(Quotes $quotes, array $changes = []): array
    {
        $quote = $quotes->quote($this->input($changes));
        return $quotes->confirm('tenant', $quote['id']);
    }

    #[DataProvider('dialects')]
    public function testAdmissionApiRetainsAcceptedPricingAndReplaysOneSharedHold(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $this->fundAdmission($pdo);
            $terms = $this->accepted($quotes);
            $catalog->publish((object) EnrichmentFixtures::definition('v2', '2026-10-13 00:00:00'), 'admin');
            $this->now = '2026-10-13 00:00:00';
            $request = $this->createStub(Request::class);
            $request->method('getBodyContents')->willReturn(json_encode(['tenantId' => 'tenant', 'quoteId' => $terms['id']]));
            $receipt = (array) (new CreditEnrichmentQuotes($quotes))->postActionAdmit($request, $this->createStub(Response::class));
            $this->assertSame('admitted', $receipt['admission']);
            $this->assertFalse($receipt['dispatchAllowed']);
            $this->assertSame('8.0000', $receipt['reservedCredits']);
            $this->assertSame(\Espo\Modules\FeatureCredits\Enrichment\CustomerProjection::quote($terms)['routes'], $receipt['routes']);
            $this->assertArrayNotHasKey('policyId', $receipt);
            $this->revision = str_repeat('b', 64); // Replays reauthorize, never restart or replace accepted work.
            $this->assertSame($receipt, \Espo\Modules\FeatureCredits\Enrichment\CustomerProjection::quote($quotes->admit('tenant', $terms['id'])));
            $this->assertSame($receipt, \Espo\Modules\FeatureCredits\Enrichment\CustomerProjection::quote($quotes->confirm('tenant', $terms['id'])));
            $this->assertSame($receipt, \Espo\Modules\FeatureCredits\Enrichment\CustomerProjection::quote($quotes->quote($this->input())));
            $this->assertSame('8.0000', $pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            foreach (['credit_usage', 'credit_reservation', 'credit_allocation'] as $table) {
                $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_request')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_transaction')->fetchColumn(), 'Only the grant is posted.');
            $this->assertSame('enrichment', $pdo->query('SELECT operation_type FROM credit_usage')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testAdmissionRequiresConfirmationAndCurrentIdentityCapabilityAndAuthorization(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes): void {
            $this->fundAdmission($pdo);
            $quote = $quotes->quote($this->input());
            try { $quotes->admit('tenant', $quote['id']); $this->fail('Unaccepted terms funded.'); } catch (Conflict) {}
            $quotes->confirm('tenant', $quote['id']);
            $pdo->exec("UPDATE contact SET cpf='01234567890'");
            try { $quotes->admit('tenant', $quote['id']); $this->fail('Changed identity admitted.'); } catch (Conflict) {}
            $pdo->exec("UPDATE contact SET cpf='52998224725'");
            $this->available = false;
            try { $quotes->admit('tenant', $quote['id']); $this->fail('Unavailable route admitted.'); } catch (Conflict) {}
            $this->available = true; $this->revision = str_repeat('b', 64);
            try { $quotes->admit('tenant', $quote['id']); $this->fail('Changed supplier terms admitted.'); } catch (Conflict) {}
            $this->revision = str_repeat('a', 64); $this->allowed = false;
            try { $quotes->admit('tenant', $quote['id']); $this->fail('Revoked permission admitted.'); } catch (Forbidden) {}
            $this->allowed = true; $this->actor = 'another-user';
            try { $quotes->admit('tenant', $quote['id']); $this->fail('Other actor admitted.'); } catch (NotFound) {}
            $this->actor = 'user';
            try { $quotes->admit('other', $quote['id']); $this->fail('Other tenant admitted.'); } catch (NotFound) {}
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
            $receipt = $quotes->admit('tenant', $quote['id']);
            $this->allowed = false;
            try { $quotes->admit('tenant', $quote['id']); $this->fail('Revoked permission replayed.'); } catch (Forbidden) {}
            $this->assertSame('admitted', $receipt['admission']);
        });
    }

    #[DataProvider('dialects')]
    public function testActiveGuardCrossesActorsAndRefreshButAllowsDisjointProductsAndReleasedWork(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes): void {
            $reservations = $this->fundAdmission($pdo, '100');
            $email = $this->accepted($quotes, ['products' => ['email']]);
            $hold = $quotes->admit('tenant', $email['id']);
            $this->actor = 'another-user';
            $combined = $this->accepted($quotes, ['requestKey' => 'refresh', 'refresh' => true]);
            try { $quotes->admit('tenant', $combined['id']); $this->fail('Concurrent overlapping product admitted.'); }
            catch (Conflict $e) {
                $this->assertSame(['reason' => 'enrichment_operation_active', 'usageId' => $hold['usageId'], 'products' => ['email']],
                    json_decode($e->getBody(), true, 8, JSON_THROW_ON_ERROR));
            }
            $phone = $this->accepted($quotes, ['requestKey' => 'phone', 'products' => ['phone']]);
            $phoneHold = $quotes->admit('tenant', $phone['id']);
            $this->assertSame('admitted', $phoneHold['admission']);
            $reservations->release('tenant', $hold['usageId'], 'enrichment:' . $email['id']);
            try { $quotes->admit('tenant', $combined['id']); $this->fail('Combined operation ignored active phone.'); } catch (Conflict) {}
            $reservations->release('tenant', $phoneHold['usageId'], 'enrichment:' . $phone['id']);
            $this->assertSame('admitted', $quotes->admit('tenant', $combined['id'])['admission']);
            $this->actor = 'user';
            $this->assertSame('released', $quotes->admit('tenant', $email['id'])['admission']);
            $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testAdmissionWriteFailureRollsBackHoldAndCanRetry(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes) use ($dialect): void {
            $this->fundAdmission($pdo);
            $terms = $this->accepted($quotes);
            $pdo->exec('ALTER TABLE enrichment_quote ADD CONSTRAINT injected_failure CHECK (usage_id IS NULL)');
            try { $quotes->admit('tenant', $terms['id']); $this->fail('Admission write fault swallowed.'); }
            catch (PDOException) { $this->assertFalse($pdo->inTransaction()); }
            finally { $pdo->exec('ALTER TABLE enrichment_quote DROP ' . self::dropCheckKeyword($pdo) . ' injected_failure'); }
            foreach (['credit_usage', 'credit_reservation', 'credit_allocation'] as $table) {
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
            $this->assertSame('0.0000', $pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('0.0000', $pdo->query('SELECT reserved_credits FROM credit_grant')->fetchColumn());
            $this->assertNull($pdo->query('SELECT admitted_at FROM enrichment_quote')->fetchColumn());
            $this->assertSame('admitted', $quotes->admit('tenant', $terms['id'])['admission']);
        });
    }

    #[DataProvider('dialects')]
    public function testSharedFundsDenyEitherConsumerAndMissingOrExpiredAgreement(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes): void {
            $reservations = $this->fundAdmission($pdo);
            $terms = $this->accepted($quotes);
            $ai = new ReservationInput('tenant', 'ai', 'first', 'execution', 'rate', '8');
            $hold = $reservations->reserve($ai);
            try { $quotes->admit('tenant', $terms['id']); $this->fail('Enrichment overspent shared funds.'); }
            catch (Conflict $e) { $this->assertSame('Insufficient eligible credits.', $e->getMessage()); }
            $reservations->release('tenant', $hold['usageId'], 'execution');
            $pdo->exec("UPDATE tenant_credit_billing_rate SET effective_until='2026-10-12 00:00:00'");
            try { $quotes->admit('tenant', $terms['id']); $this->fail('Expired agreement admitted.'); } catch (Conflict) {}
            $pdo->exec('UPDATE tenant_credit_billing_rate SET effective_until=NULL');
            $quotes->admit('tenant', $terms['id']);
            try { $reservations->reserve(new ReservationInput('tenant', 'ai', 'second', 'execution', 'rate', '8')); $this->fail('AI overspent enrichment hold.'); }
            catch (Conflict $e) { $this->assertSame('Insufficient eligible credits.', $e->getMessage()); }
            $this->assertSame('8.0000', $pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testOriginalGrantReleaseBeforeAndAfterExpirationAndNoReopening(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes): void {
            $reservations = $this->fundAdmission($pdo, '10', '2026-10-13 00:00:00');
            $first = $this->accepted($quotes);
            $hold = $quotes->admit('tenant', $first['id']);
            $reservations->release('tenant', $hold['usageId'], 'enrichment:' . $first['id']);
            $this->assertSame('8.0000', $pdo->query('SELECT released_credits FROM credit_allocation')->fetchColumn());
            $this->assertSame('10.0000', $pdo->query('SELECT remaining_credits FROM credit_grant')->fetchColumn());
            $second = $this->accepted($quotes, ['requestKey' => 'second']);
            $hold = $quotes->admit('tenant', $second['id']);
            $this->now = '2026-10-13 00:00:00';
            $release = $reservations->release('tenant', $hold['usageId'], 'enrichment:' . $second['id']);
            $this->assertSame($release, $reservations->release('tenant', $hold['usageId'], 'enrichment:' . $second['id']));
            $this->assertSame('released', $quotes->admit('tenant', $second['id'])['admission']);
            $this->assertSame('0.0000', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('0.0000', $pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('0.0000', $pdo->query('SELECT remaining_credits FROM credit_grant')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(DISTINCT grant_id) FROM credit_allocation')->fetchColumn());
            $q = $pdo->prepare('SELECT expired_credits FROM credit_allocation WHERE reservation_id=?'); $q->execute([$hold['reservationId']]);
            $this->assertSame('8.0000', $q->fetchColumn());
            $third = $this->accepted($quotes, ['requestKey' => 'expired']);
            try { $quotes->admit('tenant', $third['id']); $this->fail('Expired credits admitted.'); } catch (Conflict) {}
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testIndependentAdmissionReplayAndCompetingProductGuards(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes) use ($dialect): void {
            $reservations = $this->fundAdmission($pdo, '100');
            $terms = $this->accepted($quotes);
            $payload = ['tenantId' => 'tenant', 'quoteId' => $terms['id']];
            $results = $this->race($pdo, $dialect, 'admit', [$payload, $payload]);
            $this->assertArrayHasKey('result', $results[0]);
            $this->assertSame($results[0], $results[1]);
            $reservations->release('tenant', $results[0]['result']['usageId'], 'enrichment:' . $terms['id']);
            $a = $this->accepted($quotes, ['requestKey' => 'a']);
            $b = $this->accepted($quotes, ['requestKey' => 'b', 'refresh' => true]);
            $results = $this->race($pdo, $dialect, 'admit', [
                ['tenantId' => 'tenant', 'quoteId' => $a['id']], ['tenantId' => 'tenant', 'quoteId' => $b['id']]]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_column($results, 'error'));
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testIndependentAiAndEnrichmentCompeteForSameFunds(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes) use ($dialect): void {
            $this->fundAdmission($pdo);
            $terms = $this->accepted($quotes);
            $results = $this->race($pdo, $dialect, 'admit', [
                ['tenantId' => 'tenant', 'quoteId' => $terms['id']], ['testOperation' => 'ai']]);
            $this->assertCount(1, array_column($results, 'result'));
            $this->assertSame([Conflict::class], array_column($results, 'error'));
            $this->assertSame('10.0000', $pdo->query('SELECT balance FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame('8.0000', $pdo->query('SELECT reserved_credits FROM tenant_credit_balance')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
            $this->assertSame('8.0000', $pdo->query('SELECT reserved_credits FROM credit_grant')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testAdmissionRechecksIdentityAfterWaitingAndRejectsClientAmounts(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes) use ($dialect): void {
            $this->fundAdmission($pdo);
            $terms = $this->accepted($quotes);
            $payload = ['tenantId' => 'tenant', 'quoteId' => $terms['id']];
            $results = $this->race($pdo, $dialect, 'admit', [$payload, $payload], "UPDATE contact SET cpf='01234567890'");
            $this->assertSame([['error' => Conflict::class], ['error' => Conflict::class]], $results);
            $controller = new CreditEnrichmentQuotes($quotes);
            foreach (['maximum' => '0.01', 'billingRateId' => 'rate', 'actorId' => 'user', 'products' => ['email']] as $key => $value) {
                $request = $this->createStub(Request::class);
                $request->method('getBodyContents')->willReturn(json_encode([...$payload, $key => $value]));
                try { $controller->postActionAdmit($request, $this->createStub(Response::class)); $this->fail('Client terms admitted.'); } catch (BadRequest) {}
            }
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testAccountAndContactSameIdHaveIndependentProductGuardsAndCompanyHold(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes): void {
            $this->fundAdmission($pdo, '100');
            $contact = $this->accepted($quotes);
            $quotes->admit('tenant', $contact['id']);
            $account = $this->accepted($quotes, ['targetType' => 'Account', 'requestKey' => 'account']);
            $this->assertSame('admitted', $quotes->admit('tenant', $account['id'])['admission']);
            $company = $this->accepted($quotes, ['targetType' => 'Account', 'products' => ['company'],
                'waterfall' => false, 'budget' => null, 'requestKey' => 'company']);
            $hold = $quotes->admit('tenant', $company['id']);
            $this->assertSame($company['maximum'], $hold['reservedCredits']);
            $this->assertSame(['apollo'], array_column($hold['routes']['company'], 'provider'));
            $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
            $this->assertAdmissionAudit($pdo);
        });
    }
}
