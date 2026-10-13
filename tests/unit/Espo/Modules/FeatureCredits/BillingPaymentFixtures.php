<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Utils\{Config, Crypt};
use Espo\Modules\FeatureCredits\Accounting\{Clock, Funding, WalletLock};
use Espo\Modules\FeatureCredits\Billing\{Accounts, Catalog, Creation, CreationStore, Payments};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Adapter, Events};
use Espo\ORM\EntityManager;
use PDO;

require_once __DIR__ . '/BillingFixtures.php';
require_once __DIR__ . '/BillingAccountFixtures.php';
require_once __DIR__ . '/AsaasFixtures.php';

final class BillingPaymentFixtures
{
    public EntityManager $manager;
    public Clock $clock;
    public Accounts $accounts;
    public Catalog $catalog;
    public Creation $creation;
    public Payments $payments;
    public Events $events;
    public AsaasTestTransport $transport;
    public AsaasTestConnections $connections;
    public WalletLock $lock;
    public Funding $funding;
    public BillingTestProviders $providers;

    public function __construct(PDO $pdo, string &$now)
    {
        $this->manager = new class ($pdo) extends EntityManager {
            public function __construct(private PDO $connection) {}
            public function getPDO(): PDO { return $this->connection; }
        };
        $this->clock = new class ($now) extends Clock {
            private string $time;
            public function __construct(string &$now) { $this->time =& $now; }
            public function now(): string { return $this->time; }
        };
        $config = new class extends Config {
            public function __construct() {}
            public function get($name, $default = null): mixed { return 'billing-creation-test-crypt-key'; }
        };
        $this->connections = new AsaasTestConnections(); $this->transport = new AsaasTestTransport();
        $this->transport->onSend = static function () use ($pdo): void { if ($pdo->inTransaction()) throw new \RuntimeException('Provider I/O under lock.'); };
        $this->providers = new BillingTestProviders();
        $this->providers->integration = 'asaas-br';
        $this->catalog = new Catalog($this->manager, $this->clock);
        $this->accounts = new Accounts($this->manager, $this->clock, $this->catalog, $this->providers);
        $store = new CreationStore($this->accounts, new Crypt($config), $this->connections);
        $this->creation = new Creation($store, new Adapter($this->transport));
        $this->lock = new WalletLock($this->manager, $this->clock); $this->funding = new Funding($this->lock);
        $this->payments = new Payments($this->manager, $this->accounts, $store, $this->connections, $this->transport, $this->lock, $this->funding);
        $this->events = new Events($this->manager, $this->connections, $this->clock, $this->payments);
    }

    public function charge(bool $subscription = false, string $tenant = 'tenant', string $key = 'buy', string $method = 'PIX', string $due = '2026-10-15'): array
    {
        if ($this->accounts->context($tenant)['revision'] === 0) $this->accounts->changeCountry($tenant, 'country', 0, 'BR', 'admin');
        $catalog = $this->catalog->current(); $offer = $subscription ? 'monthly-br' : 'pack-br';
        $accepted = $this->accounts->accept($tenant, $key, $this->accounts->context($tenant)['revision'], $catalog->id, $offer, $catalog->offers[$offer]->termsId, 'admin');
        $customer = $this->creation->customer($tenant, $accepted['obligationId'], AsaasTestTransport::profile());
        $charge = $this->creation->charge($tenant, $accepted['obligationId'], $customer['creationId'], $method, $due);
        return [$accepted, $charge];
    }

    public function payment(array $charge, string $id = 'pay_renewal', string $due = '2026-10-15', string $status = 'RECEIVED'): string
    {
        foreach ($this->transport->objects as $reference => $object) if ($object['id'] === $charge['externalId']) {
            $payment = $object;
            if ($charge['kind'] === 'subscription') { $reference = $id; $payment['subscription'] = $charge['externalId']; $payment['id'] = $id; }
            $payment['object'] = 'payment'; $payment['status'] = $status; $payment['originalDueDate'] = $due;
            $payment['dueDate'] = $due; $payment['deleted'] = false;
            $this->transport->objects[$reference] = $payment;
            return $payment['id'];
        }
        throw new \RuntimeException('Fixture charge not found.');
    }

    public function read(string $id, ?string $hint = null, string $integration = 'asaas-br'): array
    {
        return $this->payments->reconcile($integration, 'sandbox', $id, $hint);
    }
}
