<?php

declare(strict_types=1);

// Explicit operator acceptance, restricted to the configured sandbox account and
// one isolated CRM tenant. This is not a checkout endpoint or production switch.
require __DIR__ . '/lib/credit-billing-sandbox-tools.php';
$root = is_file(getcwd() . '/bootstrap.php') ? getcwd() : dirname(__DIR__);
chdir($root); require 'bootstrap.php';

use Espo\Core\Utils\{Config, Crypt};
use Espo\Modules\FeatureCredits\Accounting\{BalanceStatus, Clock, ConsistencyAudit, Funding, WalletLock};
use Espo\Modules\FeatureCredits\Billing\{Accounts, Catalog, Creation, CreationStore, Payments, ProviderCapability, Providers};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Adapter, Command, Connection, Connections, Events, Transport};
use Espo\ORM\EntityManager;
use Monostax\CreditBillingSandbox\{Http, Journal, Webhook};

try {
    $action = $argv[1] ?? getenv('CREDIT_BILLING_SANDBOX_ACTION');
    if (!in_array($action, ['status', 'webhook', 'prepare', 'create', 'confirm', 'reconcile'], true)) throw new RuntimeException('invalid_sandbox_action');
    $app = new Espo\Core\Application(); $app->setupSystemUser();
    $container = $app->getContainer(); $factory = $app->getInjectableFactory();
    $manager = $container->getByClass(EntityManager::class);
    $config = $container->getByClass(Config::class); $clock = $factory->create(Clock::class);
    $baseConnections = $factory->create(Connections::class);
    $base = $baseConnections->get('asaas-sandbox');
    if ($base->environment !== 'sandbox' || $base->creationEnabled || $config->get('featureCreditsBillingReconciliationEnabled') !== false ||
        $factory->create(Providers::class)->capability('asaas') !== null) throw new RuntimeException('unexpected_global_billing_activation');
    $apiKey = getenv('BILLING_ASAAS_SANDBOX_API_KEY'); $wallet = getenv('BILLING_ASAAS_SANDBOX_WALLET_ID');
    $token = getenv('BILLING_ASAAS_SANDBOX_WEBHOOK_TOKEN');
    if (!is_string($apiKey) || !is_string($wallet) || $wallet === '' || !is_string($token) ||
        $baseConnections->authenticate('asaas-sandbox', $token) !== 'sandbox') throw new RuntimeException('sandbox_credentials_unavailable');
    // Explicit CLI-scoped sandbox permission. Persisted config and public sales stay disabled.
    $connection = new Connection('asaas-sandbox', 'sandbox', $apiKey, true);
    $http = new Http(); $walletResult = $http->send($connection, 'GET', '/wallets/');
    $matches = array_filter($walletResult->data['data'] ?? [], static fn ($row) => ($row['object'] ?? null) === 'wallet' && ($row['id'] ?? null) === $wallet);
    if ($walletResult->status !== 200 || count($matches) !== 1) throw new RuntimeException('sandbox_account_mismatch');
    $journal = new Journal('data/credit-billing-sandbox-acceptance-v1.json', hash('sha256', $wallet));
    $result = ['action' => $action, 'checkedAt' => gmdate('c'),
        'workspaceCatalogOverride' => getenv('CREDIT_BILLING_WORKSPACE_CATALOG') === '1'];
    if ($action === 'webhook') {
        $result['webhook'] = Webhook::ensure($http, $connection, $journal, $token);
    } elseif ($action === 'prepare') {
        $slug = 'credit-billing-sandbox-acceptance-v1';
        $tenant = $manager->getRDBRepository('Tenant')->where(['slug' => $slug])->findOne();
        if (!$tenant) {
            $tenant = $manager->getNewEntity('Tenant');
            $tenant->set(['name' => 'Credit Billing Sandbox Acceptance', 'slug' => $slug,
                'description' => 'Isolated synthetic sandbox billing acceptance authorized 2026-10-12. No customer users or production execution cutover.']);
            $manager->saveEntity($tenant);
        }
        if ($tenant->get('name') !== 'Credit Billing Sandbox Acceptance' ||
            (isset($journal->state['tenantId']) && $journal->state['tenantId'] !== $tenant->getId())) throw new RuntimeException('sandbox_tenant_binding_conflict');
        $journal->state['tenantId'] = $tenant->getId();
        $journal->state['effectiveFrom'] ??= gmdate('Y-m-d H:i:s', time() + 20);
        $journal->save();
        $input = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/billing/launch-2026-10-12.json'), false, 32, JSON_THROW_ON_ERROR);
        $input->effectiveFrom = $journal->state['effectiveFrom'];
        $catalog = new Catalog($manager, $clock);
        $receipt = $catalog->publish($input, 'system');
        $journal->state['catalog'] = $receipt; $journal->save();
        $result['tenantId'] = $tenant->getId(); $result['catalogId'] = $receipt['catalog']['catalogId'];
        $result['effectiveFrom'] = $input->effectiveFrom;
    } elseif (in_array($action, ['create', 'confirm', 'reconcile'], true)) {
        $tenantId = $journal->state['tenantId'] ?? '';
        $tenant = $manager->getEntityById('Tenant', $tenantId);
        if (!$tenant || $tenant->get('slug') !== 'credit-billing-sandbox-acceptance-v1' ||
            $tenant->get('name') !== 'Credit Billing Sandbox Acceptance') throw new RuntimeException('sandbox_tenant_not_prepared');
        $providers = new class ($config, new Connections($config)) extends Providers {
            public function capability(?string $provider): ?ProviderCapability
            { return $provider === 'asaas' ? new ProviderCapability('asaas', 'asaas-sandbox', true, ['BRL'], ['subscription']) : null; }
        };
        $connections = new class ($config, $connection) extends Connections {
            public function __construct(Config $config, private Connection $sandbox) { parent::__construct($config); }
            public function get(string $id): Connection {
                $original = parent::get($id);
                if ($id !== $this->sandbox->id || $original->environment !== 'sandbox') throw new RuntimeException('sandbox_connection_mismatch');
                return $this->sandbox;
            }
        };
        $catalog = new Catalog($manager, $clock);
        $accounts = new Accounts($manager, $clock, $catalog, $providers);
        $store = new CreationStore($accounts, $factory->create(Crypt::class), $connections);
        $transport = new class extends Transport {
            public array $observations = [];
            public function send(Connection $connection, string $method, string $path, ?string $json = null, array $query = []): \Espo\Modules\FeatureCredits\Billing\Asaas\Response {
                $response = parent::send($connection, $method, $path, $json, $query);
                $codes = [];
                foreach ($response->data['errors'] ?? [] as $error) if (is_string($error['code'] ?? null) && preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $error['code'])) $codes[] = $error['code'];
                $this->observations[] = ['method' => $method, 'path' => $path, 'status' => $response->status,
                    'responseFieldCount' => is_array($response->data) ? count($response->data) : 0,
                    'providerStatus' => is_string($response->data['status'] ?? null) ? $response->data['status'] : null, 'errorCodes' => $codes];
                return $response;
            }
        };
        $creation = new Creation($store, new Adapter($transport));
        if ($action === 'create') {
            $context = $accounts->context($tenantId);
            if ($context['revision'] === 0) $accounts->changeCountry($tenantId, 'sandbox-country-v1', 0, 'BR', 'system');
            $context = $accounts->context($tenantId); $definition = $catalog->current();
            if ($definition->id !== $journal->state['catalog']['catalog']['catalogId']) throw new RuntimeException('sandbox_catalog_changed');
            // Replay normally returns before re-reading locked commercial terms.
            // Exercise the deployed locking path explicitly without a new obligation.
            $pdo = $manager->getPDO(); $pdo->beginTransaction();
            try {
                if ($catalog->currentForAcceptance()->id !== $definition->id) throw new RuntimeException('sandbox_catalog_changed');
                $result['catalogLockingReadPassed'] = true;
            } finally { $pdo->rollBack(); }
            $terms = $definition->offers['monthly-2000-br'];
            $accepted = $accounts->accept($tenantId, 'sandbox-subscription-v1', $context['revision'], $definition->id, $terms->offerId, $terms->termsId, 'system');
            $customer = $creation->customer($tenantId, $accepted['obligationId'], [
                'name' => 'Monostax Synthetic Sandbox Payer', 'cpfCnpj' => '11144477735', 'email' => null, 'notificationDisabled' => true]);
            if ($customer['state'] !== 'bound') $customer = $creation->reconcile($tenantId, $customer['creationId']);
            $journal->state['customer'] = $customer; $journal->state['accepted'] = $accepted;
            $journal->state['dueDate'] ??= gmdate('Y-m-d'); $journal->save();
            $result['customer'] = $customer;
            if ($customer['state'] === 'bound') {
                $charge = $creation->charge($tenantId, $accepted['obligationId'], $customer['creationId'], 'BOLETO', $journal->state['dueDate']);
                if ($charge['state'] !== 'bound') $charge = $creation->reconcile($tenantId, $charge['creationId']);
                $journal->state['subscription'] = $charge; $journal->save(); $result['subscription'] = $charge;
            }
        } else {
            $subscription = $journal->state['subscription']['externalId'] ?? '';
            Command::externalId('subscription', $subscription);
            $payments = $http->all($connection, '/subscriptions/' . $subscription . '/payments');
            $candidates = array_values(array_filter($payments, static fn ($row) => ($row['subscription'] ?? null) === $subscription &&
                ($row['originalDueDate'] ?? null) === $journal->state['dueDate'] && ($row['customer'] ?? null) === $journal->state['customer']['externalId']));
            if (count($candidates) !== 1) throw new RuntimeException('sandbox_payment_not_uniquely_discovered');
            $paymentId = $candidates[0]['id']; Command::externalId('payment', $paymentId);
            if (isset($journal->state['paymentId']) && $journal->state['paymentId'] !== $paymentId) throw new RuntimeException('sandbox_payment_identity_changed');
            $journal->state['paymentId'] = $paymentId; $journal->save();
            if ($action === 'confirm') {
                $observed = $transport->send($connection, 'GET', '/payments/' . $paymentId);
                if ($observed->status !== 200 || ($observed->data['customer'] ?? null) !== $journal->state['customer']['externalId']) throw new RuntimeException('sandbox_payment_lookup_failed');
                if (!in_array($observed->data['status'] ?? null, ['CONFIRMED', 'RECEIVED'], true)) {
                    if (!$journal->claim('confirm', hash('sha256', $paymentId))) throw new RuntimeException('sandbox_confirmation_unresolved_lookup_only');
                    $confirmation = $http->send($connection, 'POST', '/sandbox/payment/' . $paymentId . '/confirm', []);
                    if ($confirmation->status !== 200) throw new RuntimeException('sandbox_confirmation_unresolved_lookup_only');
                    $journal->complete('confirm', ['paymentId' => $paymentId, 'httpStatus' => $confirmation->status]);
                }
            }
            $lock = new WalletLock($manager, $clock); $funding = new Funding($lock);
            $service = new Payments($manager, $accounts, $store, $connections, $transport, $lock, $funding);
            $first = $service->reconcile('asaas-sandbox', 'sandbox', $paymentId, $subscription);
            $replay = $service->reconcile('asaas-sandbox', 'sandbox', $paymentId, $subscription);
            if ($first !== $replay) throw new RuntimeException('sandbox_funding_replay_changed');
            $result['payment'] = $first; $result['replayIdentical'] = true;
            // Explicit bounded inbox drain through the real service. Scheduled activation stays off.
            $result['eventsProcessed'] = (new Events($manager, $connections, $clock, $service))->processBatch();
            $result['audit'] = (new ConsistencyAudit($manager, $clock))->inspect($tenantId);
            $result['balance'] = (new BalanceStatus($manager, $clock))->inspect($tenantId);
            $journal->state['lastPaymentResult'] = $first; $journal->save();
        }
        $result['transport'] = $transport->observations;
    } else {
        $result['steps'] = $journal->state['steps'];
        $result['tenantId'] = $journal->state['tenantId'] ?? null;
        if ($result['tenantId'] !== null) {
            $pdo = $manager->getPDO();
            $result['databaseVersion'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            foreach (['credit_billing_obligation', 'credit_billing_creation', 'credit_billing_payment', 'credit_billing_period',
                'credit_grant', 'credit_transaction'] as $table) {
                $q = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE tenant_id=?"); $q->execute([$result['tenantId']]);
                $result['sandboxRows'][$table] = (int) $q->fetchColumn();
            }
            $q = $pdo->prepare('SELECT event_type, state, last_reason FROM credit_billing_event WHERE payment_id=? ORDER BY received_at, id');
            $q->execute([$journal->state['paymentId'] ?? '']); $result['providerEvents'] = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach (['sandbox' => $result['tenantId'], 'monostax' => '6a149bca576e695c8'] as $label => $tenantId) {
                $result['audit'][$label] = (new ConsistencyAudit($manager, $clock))->inspect($tenantId);
                $result['balance'][$label] = (new BalanceStatus($manager, $clock))->inspect($tenantId);
            }
            if (isset($journal->state['subscription']['externalId'])) {
                $observed = (new Transport())->send($base, 'GET', '/subscriptions/' . $journal->state['subscription']['externalId']);
                if ($observed->status !== 200) throw new RuntimeException('sandbox_subscription_lookup_failed');
                $result['providerSubscription'] = array_intersect_key($observed->data ?? [], array_flip(['id', 'status', 'cycle', 'value', 'nextDueDate']));
            }
        }
    }
    $result['globalCreationEnabled'] = $base->creationEnabled;
    $result['globalReconciliationEnabled'] = $config->get('featureCreditsBillingReconciliationEnabled');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    // Only fixed internal reason codes or generic class names; never provider bodies, tokens or payer details.
    $message = preg_match('/^[a-z_]{1,100}$/D', $e->getMessage()) ? $e->getMessage() : 'sandbox_operation_failed';
    echo json_encode(['action' => $action ?? null, 'error' => $message, 'class' => $e::class,
        'sqlState' => $e instanceof PDOException ? ($e->errorInfo[0] ?? null) : null,
        'databaseCode' => $e instanceof PDOException ? ($e->errorInfo[1] ?? null) : null,
        'location' => basename($e->getFile()) . ':' . $e->getLine(),
        'transport' => isset($transport) ? $transport->observations : []], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    exit(1);
}
