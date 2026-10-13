<?php

declare(strict_types=1);

use Espo\Core\Acl;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Enrichment\Access;
use Espo\Modules\FeatureCredits\Enrichment\Catalog;
use Espo\Modules\FeatureCredits\Enrichment\ProviderAvailability;
use Espo\Modules\FeatureCredits\Enrichment\QuoteInput;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use Espo\Modules\FeatureCredits\Enrichment\RoutePlanner;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

require 'vendor/autoload.php';

[$script, $dialect, $operation, $payload, $now] = $argv;
$pdo = new PDO(getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN'), 'credits_test', 'credits_test',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn() !== 'feature_credits_schema_test') {
    throw new RuntimeException('Disposable test database required.');
}
$pdo->exec($dialect === 'Mysql' ? 'SET SESSION innodb_lock_wait_timeout = 10' : "SET lock_timeout = '10s'");
$manager = new class ($pdo) extends EntityManager {
    public function __construct(private PDO $connection) {}
    public function getPDO(): PDO { return $this->connection; }
    public function getEntityById(string $entityType, string $id): ?Entity
    {
        $table = match ($entityType) { 'Contact' => 'contact', 'Account' => 'account', 'Tenant' => 'tenant', 'ContactChannelIdentity' => 'contact_channel_identity' };
        $q = $this->connection->prepare("SELECT * FROM $table WHERE id=?"); $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $values = [];
        foreach ($row as $key => $value) $values[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))))] = $value;
        $entity = new BaseEntity($entityType, ['attributes' => array_fill_keys(array_keys($values), ['type' => 'varchar'])]);
        $entity->set($values);
        return $entity;
    }
};
$clock = new class ($now) extends Clock {
    public function __construct(private string $time) {}
    public function now(): string { return $this->time; }
};
// Fixed authenticated fixture context only; the real Access gates are separately tested.
$user = new class extends User {
    public function __construct() {}
    public function getId(): string { return 'user'; }
    public function isPortal(): bool { return false; }
    public function get(string $attribute): mixed { return null; }
};
$acl = new class extends Acl {
    public function __construct() {}
    public function getPermissionLevel(string $permission): string { return 'yes'; }
    public function checkScope(string $scope, ?string $action = null): bool { return true; }
    public function check($subject, ?string $action = null): bool { return true; }
    public function checkField(string $scope, string $field, string $action = 'read'): bool { return true; }
};
$tenants = new class extends UserTenantResolver {
    public function __construct() {}
    public function canActForTenant(User $user, string $tenantId): bool { return $tenantId === 'tenant'; }
};
$availability = new class extends ProviderAvailability {
    public function __construct() {}
    public function inspect(string $tenantId, string $type, array $products, array $identity, bool $waterfall = true): array
    {
        if (in_array($products, [['employees'], ['socios'], ['cnpj']], true)) {
            $providers = match ($products[0]) {
                'employees' => ['apollo'], 'socios' => $waterfall ? ['cpfcnpj', 'hub'] : ['cpfcnpj'], 'cnpj' => ['registrobr'],
            };
            return array_fill_keys($providers, array_fill_keys($products, str_repeat('a', 64)));
        }
        return array_fill_keys($waterfall ? ['apollo', 'cpfcnpj', 'hub'] : ['apollo'], array_fill_keys($products, str_repeat('a', 64)));
    }
};
$lock = new \Espo\Modules\FeatureCredits\Accounting\WalletLock($manager, $clock);
$reservations = new \Espo\Modules\FeatureCredits\Accounting\Reservations($lock, new \Espo\Modules\FeatureCredits\Accounting\Funding($lock));
$quotes = new Quotes($manager, $clock, new Access($user, $acl, $tenants, $manager), new Catalog($manager, $clock), $availability, new RoutePlanner(), $lock, $reservations);
$actorAccess = new class(new Access($user,$acl,$tenants,$manager)) extends \Espo\Modules\FeatureCredits\Enrichment\ActorAccess {
    public function __construct(private Access $access) {}
    public function forActor(string $actorId): Access {
        if ($actorId !== 'user') throw new \Espo\Core\Exceptions\Forbidden();
        return $this->access;
    }
};
$supplier = new class extends \Espo\Modules\FeatureCredits\Enrichment\Providers\Supplier {
    public function __construct() {}
    protected function provider(): string { return 'fixture'; }
    protected function eligible(string $type,string $product,array $identity): bool { return true; }
    public function revision(string $tenantId,string $targetType,string $product,array $identity): ?string { return str_repeat('a',64); }
    public function lookup(string $type,string $product,array $identity,int $timeoutMs=30000): \Espo\Modules\FeatureCredits\Enrichment\Providers\Result {
        return new \Espo\Modules\FeatureCredits\Enrichment\Providers\Result('match',['fixture@example.test']);
    }
};
$registry = new class($supplier) extends \Espo\Modules\FeatureCredits\Enrichment\Providers\Registry {
    public function __construct(private \Espo\Modules\FeatureCredits\Enrichment\Providers\Supplier $supplier) {}
    public function get(string $provider): \Espo\Modules\FeatureCredits\Enrichment\Providers\Supplier { return $this->supplier; }
};
$cryptConfig = new class extends \Espo\Core\Utils\Config {
    public function __construct() {}
    public function get(string $name, mixed $default = null): mixed { return $name === 'cryptKey' ? 'enrichment-fixture-key' : $default; }
};
$execution = new \Espo\Modules\FeatureCredits\Enrichment\Execution($manager,$lock,$actorAccess,$registry,
    new \Espo\Modules\FeatureCredits\Accounting\FixedSettlement(new \Espo\Modules\FeatureCredits\Accounting\Funding($lock)),
    new \Espo\Core\Utils\Crypt($cryptConfig),$clock);
$purchases = new \Espo\Modules\FeatureCredits\Enrichment\Purchases($lock,
    new \Espo\Modules\FeatureCredits\Accounting\FixedSettlement(new \Espo\Modules\FeatureCredits\Accounting\Funding($lock)),
    $actorAccess,$availability,new \Espo\Core\Utils\Crypt($cryptConfig));
$cacheCryptConfig = new class extends \Espo\Core\Utils\Config {
    public function __construct() {}
    public function get(string $name,mixed $default = null): mixed { return 'enrichment-test-encryption-key'; }
};
$cacheCrypt = new \Espo\Core\Utils\Crypt($cacheCryptConfig);
$cachedExecution = new \Espo\Modules\FeatureCredits\Enrichment\Execution($manager,$lock,$actorAccess,$registry,
    new \Espo\Modules\FeatureCredits\Accounting\FixedSettlement(new \Espo\Modules\FeatureCredits\Accounting\Funding($lock)),$cacheCrypt,$clock);
$cache = new \Espo\Modules\FeatureCredits\Enrichment\CandidateCache($lock,$cacheCrypt,$actorAccess,$availability);
$cachedPurchases = new \Espo\Modules\FeatureCredits\Enrichment\Purchases($lock,
    new \Espo\Modules\FeatureCredits\Accounting\FixedSettlement(new \Espo\Modules\FeatureCredits\Accounting\Funding($lock)),
    $actorAccess,$availability,$cacheCrypt);
$discoverySupplier = new class implements \Espo\Modules\FeatureCredits\Enrichment\DiscoverySupplier {
    public function revision(string $tenantId,string $targetType,string $product,array $identity): ?string { return str_repeat('a',64); }
    public function retentionSeconds(string $product): int { return 86400; }
    public function execute(array $request,int $timeoutMs): \Espo\Modules\FeatureCredits\Enrichment\DiscoveryResult {
        return new \Espo\Modules\FeatureCredits\Enrichment\DiscoveryResult('no_match');
    }
};
$discoverySuppliers = new class($discoverySupplier) extends \Espo\Modules\FeatureCredits\Enrichment\DiscoverySuppliers {
    public function __construct(private \Espo\Modules\FeatureCredits\Enrichment\DiscoverySupplier $supplier) {}
    public function get(string $provider): \Espo\Modules\FeatureCredits\Enrichment\DiscoverySupplier { return $this->supplier; }
};
$discoveryCalls = new \Espo\Modules\FeatureCredits\Enrichment\DiscoveryCalls($lock,$cache,$discoverySuppliers,$cacheCrypt);
$discoveryResult = static function (object $value): \Espo\Modules\FeatureCredits\Enrichment\DiscoveryResult {
    $data = json_decode(json_encode($value,JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR);
    return new \Espo\Modules\FeatureCredits\Enrichment\DiscoveryResult($data['state'],$data['items'] ?? [],$data['nextCursor'] ?? null);
};
$input = json_decode(base64_decode($payload), false, 32, JSON_THROW_ON_ERROR);
fwrite(STDOUT, "ready\n"); fflush(STDOUT);
try {
    $operation = $input->testOperation ?? $operation;
    if ($operation === 'linkedin-mutate') {
        $pdo->beginTransaction();
        $identity = $manager->getEntityById('ContactChannelIdentity','own');
        $identity->set('sourceId','https://linkedin.com/in/changed-by-worker');
        (new \Espo\Modules\Chatwoot\Hooks\ContactChannelIdentity\LinkedInProfile($manager))->beforeSave($identity,[]);
        $pdo->prepare('UPDATE contact_channel_identity SET source_id=? WHERE id=?')->execute([$identity->get('sourceId'),'own']);
        $pdo->commit();
        print json_encode(['result' => 'changed'],JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    if ($operation === 'execution-hang') {
        $claim = $execution->claim($input->tenantId,$input->quoteId);
        // Simulate the provider returning before the worker durably records the outcome.
        $supplier->lookup($claim['type'],$claim['product'],$claim['identity']);
        print json_encode(['providerReturned' => true, 'claim' => $claim],JSON_THROW_ON_ERROR) . "\n";
        fflush(STDOUT);
        while (true) sleep(10);
    }
    if ($operation === 'purchase-hang') {
        $receipt = $purchases->purchase($input->tenantId,$input->quoteId,$input->key,$input->provider,(array) $input->values,$input->acquiredAt);
        print json_encode(['purchaseCommitted' => $receipt],JSON_THROW_ON_ERROR) . "\n";
        fflush(STDOUT);
        while (true) sleep(10);
    }
    if (in_array($operation,['discovery-hang','discovery-record-hang'],true)) {
        $claim = $discoveryCalls->page($input->tenantId,$input->quoteId,$input->provider,$input->page,$input->cursor ?? null);
        $request = $discoveryCalls->dispatch($input->tenantId,$input->quoteId,$claim['id'],$claim['token']);
        $discoverySupplier->execute($request,$request['timeoutMs']);
        if ($operation === 'discovery-record-hang') {
            $discoveryCalls->record($input->tenantId,$input->quoteId,$claim['id'],$claim['token'],$discoveryResult($input->result));
        }
        print json_encode(['boundary' => $operation,'id' => $claim['id']],JSON_THROW_ON_ERROR) . "\n";
        fflush(STDOUT);
        while (true) sleep(10);
    }
    $result = match ($operation) {
        'discovery-page' => $discoveryCalls->page($input->tenantId,$input->quoteId,$input->provider,$input->page,$input->cursor ?? null),
        'discovery-dispatch' => $discoveryCalls->dispatch($input->tenantId,$input->quoteId,$input->id,$input->token),
        'discovery-record' => $discoveryCalls->record($input->tenantId,$input->quoteId,$input->id,$input->token,$discoveryResult($input->result)),
        'cache-purchase' => $cachedPurchases->purchaseCached($input->tenantId,$input->quoteId,$input->cacheId),
        'cache-store' => $cache->store($input->tenantId,$input->quoteId,$input->provider,$input->key,$input->candidateKey,
            $input->acquiredAt,$input->expiresAt,(array) $input->values,[],true),
        'purchase' => $purchases->purchase($input->tenantId,$input->quoteId,$input->key,$input->provider,(array) $input->values,$input->acquiredAt),
        'purchase-close' => $purchases->close($input->tenantId,$input->quoteId),
        'quote' => $quotes->quote(new QuoteInput($input)),
        'ai' => $reservations->reserve(new \Espo\Modules\FeatureCredits\Accounting\ReservationInput(
            'tenant', 'ai', 'competing-ai', 'execution', 'rate', '8')),
        'execution-claim' => $execution->claim($input->tenantId,$input->quoteId),
        'execution-run' => $execution->run($input->tenantId,$input->quoteId),
        'cached-execution-run' => $cachedExecution->run($input->tenantId,$input->quoteId),
        'execution-record' => $execution->record($input->tenantId,$input->quoteId,$input->attemptId,$input->claimToken,
            new \Espo\Modules\FeatureCredits\Enrichment\Providers\Result('match',['fixture@example.test'])),
        default => $quotes->$operation($input->tenantId, $input->quoteId),
    };
    print json_encode(['result' => $result], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    print json_encode(['error' => get_class($e)], JSON_THROW_ON_ERROR) . "\n";
}
