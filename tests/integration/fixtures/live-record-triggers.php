<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Espo\Modules\FeatureLiveRecords\Persistence\TriggerSql;

$pdo = new PDO(getenv('LIVE_RECORD_TEST_DSN'), getenv('LIVE_RECORD_TEST_USER'), getenv('LIVE_RECORD_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void {
    if (!$value) throw new RuntimeException($message);
    $checks++;
};
$pdo->exec('CREATE TABLE live_test_record (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64), name VARCHAR(255), version_number INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE live_record_outbox (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(64), intent TEXT,
    created_at TIMESTAMP, due_at TIMESTAMP, attempts INTEGER)');
try {
    foreach (TriggerSql::statements($driver, 'LiveTestRecord', 'live_test_record', true) as $sql) $pdo->exec($sql);
    $pdo->exec("INSERT INTO live_test_record (id, tenant_id, name) VALUES ('record', 'tenant-a', 'original')");
    $check((int) $pdo->query('SELECT version_number FROM live_test_record')->fetchColumn() === 1, 'Insert revision missing');
    $check((int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn() === 1, 'Insert intent missing');
    $pdo->beginTransaction();
    $pdo->exec("UPDATE live_test_record SET name = 'rolled-back'");
    $pdo->rollBack();
    $check($pdo->query('SELECT name FROM live_test_record')->fetchColumn() === 'original', 'Mutation survived rollback');
    $check((int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn() === 1, 'Intent survived rollback');
    $pdo->exec("UPDATE live_test_record SET name = 'external-sql'");
    $check((int) $pdo->query('SELECT version_number FROM live_test_record')->fetchColumn() === 2, 'Direct SQL did not advance revision');
    $check((int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn() === 2, 'Direct SQL intent missing');
    $stale = $pdo->exec("UPDATE live_test_record SET name = 'stale', version_number = 2 WHERE version_number = 1");
    $check($stale === 0, 'Stale version overwritten');
    $pdo->exec("UPDATE live_test_record SET tenant_id = 'tenant-b', version_number = 3");
    $check((int) $pdo->query('SELECT version_number FROM live_test_record')->fetchColumn() === 3, 'Explicit CAS revision was incremented twice');
    $counts = $pdo->query('SELECT tenant_id, COUNT(*) AS n FROM live_record_outbox GROUP BY tenant_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $check((int) $counts['tenant-a'] === 3 && (int) $counts['tenant-b'] === 1, 'Transfer was not partitioned');
    foreach ($pdo->query('SELECT intent FROM live_record_outbox')->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $intent = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $records = $intent['records'];
        $check(count($records) === 1 && count($records[0]) === 2 &&
            $records[0]['type'] === 'LiveTestRecord' && $records[0]['id'] === 'record', 'Invalid identifier contract');
        $check(!str_contains($json, 'external-sql'), 'Record content leaked');
    }
    // A trigger insertion failure aborts even an autocommit external writer.
    $pdo->exec('ALTER TABLE live_record_outbox RENAME TO live_record_outbox_unavailable');
    try {
        $pdo->exec("UPDATE live_test_record SET name = 'must-rollback'");
        throw new RuntimeException('Missing outbox did not block mutation');
    } catch (PDOException) {}
    $pdo->exec('ALTER TABLE live_record_outbox_unavailable RENAME TO live_record_outbox');
    $check($pdo->query('SELECT name FROM live_test_record')->fetchColumn() === 'external-sql', 'Failed intent committed external mutation');
    $pdo->exec('DELETE FROM live_test_record');
    $check((int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn() === 5, 'Hard delete intent missing');
    fwrite(STDOUT, "Live record database triggers: $checks checks passed ($driver).\n");
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('DROP TABLE live_test_record');
    $pdo->exec('DROP TABLE live_record_outbox');
}
