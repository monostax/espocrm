<?php

declare(strict_types=1);

/** Disposable MySQL/PostgreSQL database only; CREATE refuses an existing application outbox. */
require __DIR__ . '/../../../vendor/autoload.php';

use Espo\Modules\FeatureLiveRecords\Persistence\TriggerSql;

$pdo = new PDO(getenv('LIVE_RECORD_TEST_DSN'), getenv('LIVE_RECORD_TEST_USER'), getenv('LIVE_RECORD_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$pdo->exec('CREATE TABLE live_record_outbox (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64), intent TEXT,
    created_at TIMESTAMP, due_at TIMESTAMP, attempts INTEGER)');
$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void {
    if (!$value) throw new RuntimeException($message);
    $checks++;
};
$count = fn () => (int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn();
try {
    $pdo->exec('CREATE TABLE live_scope_document (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64),
        knowledge_record_type VARCHAR(64), version_number INTEGER DEFAULT 0, name VARCHAR(64))');
    $pdo->exec('CREATE TABLE live_scope_identity (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64),
        contact_id VARCHAR(17), version_number INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE live_old_record (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64), version_number INTEGER DEFAULT 0)');
    foreach (TriggerSql::statements($driver, 'Credential', 'live_old_record', true) as $sql) $pdo->exec($sql);
    foreach (TriggerSql::statements($driver, 'Document', 'live_scope_document', true, true) as $sql) $pdo->exec($sql);
    foreach (TriggerSql::statements($driver, 'ContactChannelIdentity', 'live_scope_identity', true, true) as $sql) $pdo->exec($sql);
    if ($driver === 'mysql') {
        $pdo->exec('CREATE TRIGGER application_audit AFTER UPDATE ON live_scope_document FOR EACH ROW SET @live_scope_audit = 1');
    } else {
        $pdo->exec('CREATE FUNCTION live_scope_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END; $$');
        $pdo->exec('CREATE TRIGGER application_audit AFTER UPDATE ON live_scope_document FOR EACH ROW EXECUTE FUNCTION live_scope_audit()');
    }

    $pdo->exec("INSERT INTO live_scope_document (id, tenant_id, knowledge_record_type) VALUES ('doc', 'tenant', 'Credential')");
    $check($count() === 0, 'Excluded parent provisioned a document notification');
    $pdo->exec("UPDATE live_scope_document SET name = 'unrelated edit'");
    $check($count() === 0, 'Unrelated document edit emitted intent');
    $pdo->exec("UPDATE live_scope_document SET knowledge_record_type = 'Contact'");
    $check($count() === 1, 'Entering the supported scope did not notify');
    $pdo->exec("UPDATE live_scope_document SET knowledge_record_type = 'Credential'");
    $check($count() === 2, 'Leaving the supported scope did not invalidate old consumers');
    $pdo->exec('UPDATE live_scope_document SET knowledge_record_type = NULL');
    $check($count() === 2, 'Unrelated NULL ownership emitted intent');
    $pdo->exec("UPDATE live_scope_document SET knowledge_record_type = 'Account'");
    $check($count() === 3, 'Account-owned document did not notify');
    $pdo->exec('UPDATE live_scope_document SET knowledge_record_type = NULL');
    $check($count() === 4, 'Removing the scoped owner did not notify');

    $pdo->exec("INSERT INTO live_scope_identity (id, tenant_id) VALUES ('identity', 'tenant')");
    $check($count() === 4, 'Unattached identity emitted intent');
    $pdo->exec("UPDATE live_scope_identity SET contact_id = 'contact'");
    $check($count() === 5, 'Contact identity did not notify');
    $pdo->exec('UPDATE live_scope_identity SET contact_id = NULL');
    $check($count() === 6, 'Unlinking a contact identity did not notify');

    TriggerSql::removeObsolete($pdo, ['live_scope_document' => true, 'live_scope_identity' => true]);
    $names = $pdo->query('SELECT trigger_name FROM information_schema.triggers WHERE ' .
        ($driver === 'mysql' ? 'trigger_schema = DATABASE()' : 'trigger_schema = current_schema()'))->fetchAll(PDO::FETCH_COLUMN);
    $oldPrefix = 'lr_' . substr(hash('sha256', 'live_old_record'), 0, 16);
    $check(!array_filter($names, fn ($name) => str_starts_with($name, $oldPrefix)), 'Retired scope triggers survived rebuild');
    $check(in_array('application_audit', $names, true), 'An unrelated application trigger was removed');
    $check(count(array_filter($names, fn ($name) => str_starts_with($name, 'lr_'))) === 10, 'Active scope triggers were removed');
    $pdo->exec("INSERT INTO live_old_record (id, tenant_id) VALUES ('retired', 'tenant')");
    $check($count() === 6, 'Retired entity still produces notifications');
    $check((int) $pdo->query('SELECT version_number FROM live_old_record')->fetchColumn() === 0, 'Retired version trigger remains installed');
    $pdo->exec("UPDATE live_scope_document SET knowledge_record_type = 'Contact'");
    $check($count() === 7, 'Active trigger stopped working after cleanup');
    foreach ($pdo->query('SELECT intent FROM live_record_outbox')->fetchAll(PDO::FETCH_COLUMN) as $intent) {
        $check(!str_contains($intent, 'unrelated edit') && !str_contains($intent, 'Credential'), 'Unrelated values or types leaked');
    }
    fwrite(STDOUT, "Live record scope triggers: $checks checks passed ($driver).\n");
} finally {
    TriggerSql::removeObsolete($pdo, []);
    foreach (['live_scope_document', 'live_scope_identity', 'live_old_record', 'live_record_outbox'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS $table");
    }
    if ($driver === 'pgsql') $pdo->exec('DROP FUNCTION IF EXISTS live_scope_audit()');
}
