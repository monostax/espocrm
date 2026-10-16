<?php

declare(strict_types=1);

use Espo\Core\Acl;
use Espo\Core\Utils\{Config, Crypt};
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\{Clock, Funding, Reservations, WalletLock};
use Espo\Modules\FeatureCredits\Enrichment\ReviewLock;
use Espo\Modules\FeatureCredits\Verification\{Access, CapabilityGate, Kind, Operations, TwilioCredentials, WahaCredentials};
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\{BaseEntity, Entity, EntityManager};

require 'vendor/autoload.php';
[$script, $dialect, $payload, $now] = $argv;
$pdo = new PDO(getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN'), 'credits_test', 'credits_test',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn() !== 'feature_credits_schema_test') {
    throw new RuntimeException('Disposable test database required.');
}
$pdo->exec($dialect === 'Mysql' ? 'SET SESSION innodb_lock_wait_timeout = 10' : "SET lock_timeout = '10s'");
$manager = new class($pdo) extends EntityManager {
    public function __construct(private PDO $connection) {}
    public function getPDO(): PDO { return $this->connection; }
    public function getEntityById(string $entityType, string $id): ?Entity {
        $table = match ($entityType) { 'Tenant' => 'tenant', 'Contact' => 'contact', 'ContactChannelIdentity' => 'contact_channel_identity' };
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
$clock = new class($now) extends Clock {
    public function __construct(private string $time) {}
    public function now(): string { return $this->time; }
};
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
$config = new class extends Config {
    public function __construct() {}
    public function get(string $name, mixed $default = null): mixed { return $name === 'cryptKey' ? 'phone-verification-test-crypt-key' : $default; }
};
$credentials = new class extends WahaCredentials {
    public function enabled(): bool { return true; }
    public function get(): array { return ['url' => 'http://unused.test', 'apiKey' => 'fixture', 'session' => 'fixture']; }
};
$crypt = new Crypt($config);
$lock = new WalletLock($manager, $clock);
$operations = new Operations($manager, $clock, new Access($user, $acl, $tenants, $manager, $crypt),
    new CapabilityGate($config, new TwilioCredentials(), $clock, whatsAppCredentials: $credentials),
    $lock, new ReviewLock($manager), new Reservations($lock, new Funding($lock)), $crypt, $config);
fwrite(STDOUT, "ready\n"); fflush(STDOUT);
print json_encode($operations->start(json_decode(base64_decode($payload), false, 16, JSON_THROW_ON_ERROR), Kind::WHATSAPP), JSON_THROW_ON_ERROR) . "\n";
