<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Espo\Modules\FeatureLiveRecords\Persistence\ChangePolicy;
use Espo\Modules\FeatureLiveRecords\Persistence\TriggerSql;
use Espo\ORM\BaseEntity;

// Disposable database only, like the other live-record SQL fixtures.
$pdo = new PDO(getenv('LIVE_RECORD_TEST_DSN'), getenv('LIVE_RECORD_TEST_USER'), getenv('LIVE_RECORD_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void {
    if (!$value) throw new RuntimeException($message);
    $checks++;
};
$pdo->exec('CREATE TABLE live_record_outbox (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64), intent TEXT,
    created_at TIMESTAMP, due_at TIMESTAMP, attempts INTEGER)');
$pdo->exec('CREATE TABLE journey_record (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64), status VARCHAR(32),
    claimed_at TIMESTAMP NULL, modified_at TIMESTAMP NULL, current_stage_id VARCHAR(17), version_number INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE chatwoot_account (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64), status VARCHAR(32),
    last_contact_sync_at TIMESTAMP NULL, contact_sync_cursor INTEGER, modified_at TIMESTAMP NULL)');
$pdo->exec('CREATE TABLE contact_channel_identity (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64), contact_id VARCHAR(17),
    source_id VARCHAR(64), ownership_status VARCHAR(32), ownership_synced_at TIMESTAMP NULL, modified_at TIMESTAMP NULL)');
$count = fn () => (int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn();
try {
    foreach (['journey_record' => 'JourneyRecord', 'chatwoot_account' => 'ChatwootAccount',
        'contact_channel_identity' => 'ContactChannelIdentity'] as $table => $type) {
        foreach (TriggerSql::statements($driver, $type, $table, $type === 'JourneyRecord', true,
            ChangePolicy::columns($pdo, $table)) as $sql) $pdo->exec($sql);
    }
    $pdo->exec("INSERT INTO journey_record (id,tenant_id,status,current_stage_id) VALUES ('record','tenant-a','Active','stage-a')");
    $entity = new BaseEntity('JourneyRecord', ['attributes' => ['id' => ['type' => 'varchar']]]);
    $entity->set('id', 'record');
    $before = ChangePolicy::snapshot($pdo, $entity);
    for ($i = 0; $i < 20; $i++) {
        $pdo->exec("UPDATE journey_record SET status = 'Processing', claimed_at = '2026-10-11 20:00:00'");
        $check(ChangePolicy::snapshot($pdo, $entity) === $before, 'ORM claim must be operational-only');
        $pdo->exec("UPDATE journey_record SET status = 'Active', claimed_at = NULL, modified_at = '2026-10-11 20:00:00'");
    }
    $check($count() === 1, 'Claim/release cycle emitted invalidations');
    $check(ChangePolicy::snapshot($pdo, $entity) === $before, 'ORM release must be operational-only');
    $check((int) $pdo->query('SELECT version_number FROM journey_record')->fetchColumn() === 41, 'CAS protection was lost');
    $pdo->exec("UPDATE journey_record SET current_stage_id = 'stage-b'");
    $check($count() === 2 && ChangePolicy::snapshot($pdo, $entity) !== $before, 'Stage change was suppressed');
    $pdo->exec("UPDATE journey_record SET status = 'Completed'");
    $check($count() === 3, 'Terminal status was suppressed');
    $pdo->exec("UPDATE journey_record SET tenant_id = 'tenant-b'");
    $check($count() === 5, 'Tenant transfer must reach both tenants');
    $pdo->beginTransaction();
    $pdo->exec("UPDATE journey_record SET current_stage_id = 'rollback'");
    $pdo->rollBack();
    $check($count() === 5, 'Rolled back intent survived');
    $pdo->exec('DELETE FROM journey_record');
    $check($count() === 6, 'Delete was suppressed');

    $pdo->exec("INSERT INTO chatwoot_account (id,tenant_id,status) VALUES ('account','tenant-a','active')");
    $pdo->exec("UPDATE chatwoot_account SET last_contact_sync_at = '2026-10-11 20:00:00', contact_sync_cursor = 400, modified_at = '2026-10-11 20:00:00'");
    $check($count() === 7, 'Sync progress emitted access reset');
    $pdo->exec("UPDATE chatwoot_account SET status = 'suspended'");
    $check($count() === 8, 'Access revocation was suppressed');
    $accessIntents = $pdo->query("SELECT intent FROM live_record_outbox WHERE intent LIKE '%access%'")->fetchAll(PDO::FETCH_COLUMN);
    $check(count($accessIntents) === 2, 'Access writes must emit resets');
    foreach ($accessIntents as $intent) $check(json_decode($intent, true)['records'] === [], 'Reset leaked record identities');

    $pdo->exec("INSERT INTO contact_channel_identity (id,tenant_id,contact_id,source_id,ownership_status)
        VALUES ('identity','tenant-a','contact','handle','unverified')");
    $pdo->exec("UPDATE contact_channel_identity SET source_id = 'handle', ownership_synced_at = '2026-10-11 20:00:00'");
    $check($count() === 9, 'Unchanged identity sync emitted invalidation');
    $pdo->exec("UPDATE contact_channel_identity SET source_id = 'Handle'");
    $check($count() === 10, 'Case-only change was suppressed');
    $pdo->exec("UPDATE contact_channel_identity SET ownership_status = 'rejected'");
    $check($count() === 11, 'Identity ownership change was suppressed');
    $pdo->exec('UPDATE contact_channel_identity SET contact_id = NULL');
    $check($count() === 12, 'Leaving scope did not invalidate the old owner');
    fwrite(STDOUT, "Live record noise suppression: $checks checks passed ($driver).\n");
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach (['journey_record', 'chatwoot_account', 'contact_channel_identity', 'live_record_outbox'] as $table) {
        $pdo->exec("DROP TABLE $table");
    }
}
