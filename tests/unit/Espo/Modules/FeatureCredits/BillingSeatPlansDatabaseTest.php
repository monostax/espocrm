<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\{MySQL80Platform, PostgreSQLPlatform};
use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\{Accounts, Catalog};
use Espo\ORM\{Entity, EntityManager};
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Accepting a seat-priced plan on both dialects: quantity in, total out, one plan per tenant. */
final class BillingSeatPlansDatabaseTest extends TestCase
{
    use SchemaFixture;

    private string $now = '2026-10-12 12:00:00';

    public static function dialects(): iterable
    {
        yield 'MySQL' => ['Mysql']; yield 'PostgreSQL' => ['Postgresql'];
    }

    #[DataProvider('dialects')]
    public function testASeatPlanIsAcceptedWithItsQuantityAndTotal(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog] = $this->published($pdo);

            $context = $accounts->context('tenant');
            $this->assertNotContains('plan-basic-br', array_column(array_column($context['offers'], 'terms'), 'offerId'),
                'Plans are sold by the self-service checkout, not the generic one.');

            $receipt = $accounts->accept(...$this->acceptance($catalog, 'plan-basic-br', 'plan', 4));
            $this->assertSame('1913.00', $receipt['terms']['price']);
            $this->assertSame(['plan' => 'basic', 'unitPrice' => '229.00', 'min' => 1, 'max' => 50, 'quantity' => 4, 'basePrice' => '997.00'],
                $receipt['terms']['seats']);
            $this->assertSame($receipt, $accounts->accept(...$this->acceptance($catalog, 'plan-basic-br', 'plan', 4)), 'Replays.');
            $this->assertSame($receipt, $accounts->readObligation('tenant', $receipt['obligationId'])['acceptance']);
            $this->assertSame($receipt['terms']['termsId'],
                $pdo->query("SELECT terms_id FROM credit_billing_obligation WHERE id = '{$receipt['obligationId']}'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testSeatsAreRequiredForPlansAndRejectedElsewhere(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog] = $this->published($pdo);

            foreach ([['plan-basic-br', null], ['monthly-br', 2]] as [$offer, $quantity]) {
                try {
                    $accounts->accept(...$this->acceptance($catalog, $offer, 'bad-' . $offer, $quantity));
                    $this->fail("{$offer} accepted a wrong quantity.");
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        });
    }

    #[DataProvider('dialects')]
    public function testAWorkspaceHoldsOnePlanAtATime(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog] = $this->published($pdo);
            $first = $accounts->accept(...$this->acceptance($catalog, 'plan-basic-br', 'plan-1', 2));

            try {
                $accounts->accept(...$this->acceptance($catalog, 'plan-advanced-br', 'plan-2', 2));
                $this->fail('A second plan was accepted while one is open.');
            } catch (Conflict $e) {
                $this->assertStringContainsString('plan subscription', $e->getMessage());
            }

            $accounts->cancel('tenant', 'cancel-1', $first['obligationId'], 'admin');
            $revision = (int) $accounts->context('tenant')['revision'];
            $this->assertSame('289.00', $accounts->accept(...$this->acceptance($catalog, 'plan-advanced-br', 'plan-2', 2, $revision))['terms']['seats']['unitPrice']);
        });
    }

    private function published(PDO $pdo): array
    {
        $manager = $this->createStub(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $manager->method('getEntityById')->willReturn($this->createStub(Entity::class));
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(fn () => $this->now);
        $catalog = new Catalog($manager, $clock);
        $accounts = new Accounts($manager, $clock, $catalog, new BillingTestProviders());

        $input = BillingFixtures::definition();
        foreach (['basic' => '229', 'advanced' => '289'] as $plan => $unit) {
            $input->offers[] = (object) ['offerId' => "plan-{$plan}-br", 'version' => 'v1', 'productId' => 'monthly',
                'productVersion' => 'v1', 'countries' => ['BR'], 'currency' => 'BRL', 'price' => '997', 'interval' => 'month',
                'seats' => (object) ['plan' => $plan, 'unitPrice' => $unit, 'min' => 1, 'max' => 50]];
        }
        $catalog->publish($input, 'admin');
        $accounts->changeCountry('tenant', 'country', 0, 'BR', 'admin');
        $this->now = $input->effectiveFrom;

        return [$accounts, $catalog];
    }

    private function acceptance(Catalog $catalog, string $offer, string $key, ?int $quantity, int $revision = 1): array
    {
        $current = $catalog->current();

        return ['tenant', $key, $revision, $current->id, $offer, $current->offers[$offer]->termsId, 'admin', $quantity];
    }

    private function database(string $dialect, callable $test): void
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        $pdo = new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = $this->schema($dialect); $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        try {
            foreach ($schema->toSql($platform) as $sql) $pdo->exec($sql);
            $pdo->exec('CREATE TABLE tenant (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec("INSERT INTO tenant (id) VALUES ('tenant'), ('other')");
            $test($pdo);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('DROP TABLE IF EXISTS tenant');
            foreach (array_reverse($schema->getTables()) as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
        }
    }
}
