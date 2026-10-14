<?php

declare(strict_types=1);

/** Run only against a disposable live_records_* database; creates the complete application schema. */
require __DIR__ . '/../../../vendor/autoload.php';

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Utils\File\Manager;
use Espo\Modules\FeatureLiveRecords\Persistence\Observer;

$database = getenv('TEST_DATABASE_NAME');
if (!$database || !str_starts_with($database, 'live_records_')) {
    throw new RuntimeException('A disposable live_records_* TEST_DATABASE_NAME is required.');
}
$source = getcwd();
$runtime = sys_get_temp_dir() . '/live-records-runtime-' . bin2hex(random_bytes(4));
$files = new Manager();
$files->mkdir($runtime);
foreach (['application', 'vendor', 'client', 'html', 'public', 'index.php'] as $path) {
    symlink($source . '/' . $path, $runtime . '/' . $path);
}
foreach (['custom', 'install'] as $path) {
    $files->copy($source . '/' . $path, $runtime . '/' . $path, true);
}
foreach (['bootstrap.php', 'command.php'] as $path) {
    copy($source . '/' . $path, $runtime . '/' . $path);
}
$files->mkdir($runtime . '/data');
chdir($runtime);
set_include_path($runtime);
require_once $runtime . '/install/core/Installer.php';
$installer = new Installer(new ApplicationParams(noErrorHandler: true));
$config = [
    'database' => [
        'platform' => getenv('TEST_DATABASE_PLATFORM') ?: 'Postgresql',
        'host' => '127.0.0.1',
        'port' => getenv('TEST_DATABASE_PORT') ?: '5432',
        'dbname' => $database,
        'user' => getenv('TEST_DATABASE_USER') ?: 'postgres',
        'password' => getenv('TEST_DATABASE_PASSWORD'),
    ],
    'siteUrl' => 'http://127.0.0.1', 'useCache' => false, 'isInstalled' => true, 'version' => '10.0.2',
];
$installer->saveData($config);
$installer->saveConfig($config);
$installer->rebuild();
$installer->setSuccess();
$app = new Application(new ApplicationParams(noErrorHandler: true));
$app->setupSystemUser();
$em = $app->getContainer()->get('entityManager');
$metadata = $app->getContainer()->get('metadata');
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$types = $metadata->get(['app', 'liveRecords', 'recordTypes']);
$check(is_array($types) && in_array('Task', $types, true), 'Merged record inventory missing.');
$check($types === \Espo\Modules\FeatureLiveRecords\Scope::RECORD_TYPES, 'Registration expanded beyond the approved screens.');
foreach ($types as $type) {
    $check($metadata->get(['entityDefs', $type, 'mutationObserverClassName']) === Observer::class,
        "$type observer missing.");
    $check($em->getMetadata()->get($type, 'versionAttribute') === 'versionNumber',
        "$type revision mapping missing.");
    $check(!$em->getMetadata()->get($type, 'concurrencyAttribute'), "$type unexpectedly rejects stale saves.");
    $em->getRDBRepository($type); // Resolve real repository + observer dependencies through the application factory.
}
fwrite(STDOUT, 'Merged live record types: ' . json_encode($types, JSON_THROW_ON_ERROR) . "\n");

$tenant = $em->createEntity('Tenant', ['name' => 'Live Records Runtime', 'slug' => 'live-records-runtime']);
$account = $em->createEntity('Account', ['name' => 'Runtime account', 'tenantId' => $tenant->getId()]);
$contact = $em->createEntity('Contact', ['lastName' => 'Runtime contact', 'tenantId' => $tenant->getId()]);
$originalAccountVersion = $account->get('versionNumber');
$originalContactVersion = $contact->get('versionNumber');
$relation = $em->getRDBRepository('Account')->getRelation($account, 'contacts');
$relation->relate($contact);
$latestAccount = $em->getEntityById('Account', $account->getId());
$latestContact = $em->getEntityById('Contact', $contact->getId());
$check($latestAccount->get('versionNumber') > $originalAccountVersion, 'Native relation did not advance parent version.');
$check($latestContact->get('versionNumber') > $originalContactVersion, 'Native relation did not advance foreign version.');
$check($relation->isRelated($contact), 'Native relation was not persisted.');

$account->set('name', 'stale snapshot edit');
$em->saveEntity($account);
$check($em->getEntityById('Account', $account->getId())->get('name') === 'stale snapshot edit',
    'Stale edit did not persist.');
$check($account->get('versionNumber') > $latestAccount->get('versionNumber'),
    'Stale edit did not advance the stored revision.');
$check($relation->isRelated($contact), 'Stale edit removed an unrelated native relation.');

$contactService = $app->getContainer()->get('recordServiceContainer')->get('Contact');
$contactService->update($contact->getId(), (object) ['lastName' => 'Stale header edit'],
    UpdateParams::create()->withVersionNumber($originalContactVersion)->withRequiredVersion());
$check($em->getEntityById('Contact', $contact->getId())->get('lastName') === 'Stale header edit',
    'Ordinary Contact edit with a stale version header did not persist.');
$contactService->update($contact->getId(), (object) ['description' => 'No version needed'],
    UpdateParams::create()->withRequiredVersion());
$check($em->getEntityById('Contact', $contact->getId())->get('description') === 'No version needed',
    'Ordinary Contact edit required a version header.');

$contactService->update($contact->getId(), (object) ['channelIdentitiesData' => [
    (object) ['channelType' => 'linkedin', 'sourceId' => 'https://www.linkedin.com/in/live-record-test', 'isPrimary' => true],
    (object) ['channelType' => 'whatsapp', 'sourceId' => '+5511999990001', 'isPrimary' => true],
    (object) ['channelType' => 'email', 'sourceId' => 'primary@example.test', 'isPrimary' => true],
]]);
$items = [];
foreach ($em->getRDBRepository('ContactChannelIdentity')->where(['contactId' => $contact->getId()])->find() as $item) {
    $items[] = (object) ['id' => $item->getId(), 'channelType' => $item->get('channelType'),
        'sourceId' => $item->get('sourceId'), 'isPrimary' => $item->get('isPrimary')];
}
$items[] = (object) ['channelType' => 'email', 'sourceId' => 'second@example.test', 'isPrimary' => false];
$contactService->update($contact->getId(), (object) ['channelIdentitiesData' => $items]);
$check($em->getRDBRepository('ContactChannelIdentity')->where(['contactId' => $contact->getId()])->count() === 4,
    'Adding a channel identity did not preserve the submitted list.');
$check($em->getEntityById('Contact', $contact->getId())->get('emailAddress') === 'primary@example.test',
    'Adding a secondary email changed the primary address.');

$pdo = $em->getPDO();
$before = (int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn();
$version = $latestAccount->get('versionNumber');
$statement = $pdo->prepare('UPDATE account SET name = ? WHERE id = ?');
$statement->execute(['external runtime write', $account->getId()]);
$check($em->getEntityById('Account', $account->getId())->get('versionNumber') > $version,
    'Rebuild-installed trigger did not advance external writer version.');
$check((int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn() > $before,
    'Rebuild-installed trigger did not emit intent.');

$before = (int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn();
try {
    $em->getTransactionManager()->run(function () use ($em, $tenant): void {
        $em->createEntity('Account', ['name' => 'must roll back', 'tenantId' => $tenant->getId()]);
        throw new RuntimeException('Injected rollback');
    });
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Injected rollback') {
        throw $error;
    }
}
$check((int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn() === $before,
    'Application rollback retained outbox entries.');

// Excluded business objects still use Record Knowledge, but their automatically
// provisioned documents/revisions must not reintroduce the notification flood.
$before = (int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn();
$lead = $em->createEntity('Lead', ['lastName' => 'Excluded lead', 'tenantId' => $tenant->getId()]);
$leadDocument = $em->getRDBRepository('Document')->where([
    'knowledgeRecordType' => 'Lead', 'knowledgeRecordId' => $lead->getId(),
])->findOne();
$check($leadDocument !== null, 'Excluded Lead did not provision its normal knowledge document.');
$leadDocument->set('name', 'Unrelated document edit');
$em->saveEntity($leadDocument);
$check((int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn() === $before,
    'Excluded record or its provisioned document emitted notifications.');

$identity = $em->createEntity('ContactChannelIdentity', [
    'tenantId' => $tenant->getId(), 'contactId' => $contact->getId(),
    'channelType' => 'email', 'sourceId' => 'live-identity@example.test',
]);
$check((int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn() > $before,
    'Contact channel identity did not emit intent.');
$before = (int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn();
$contact = $em->getEntityById('Contact', $contact->getId());
$contact->set('emailAddress', 'live-address@example.test');
$em->saveEntity($contact);
$address = $em->getRDBRepository('EmailAddress')->where(['lower' => 'live-address@example.test'])->findOne();
$check($address !== null, 'Contact address was not persisted.');
$before = (int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn();
$address->set('invalid', true);
$em->saveEntity($address);
$check((int) $pdo->query('SELECT COUNT(*) FROM live_record_outbox')->fetchColumn() > $before,
    'A direct address repository update did not reach its scoped Contact.');

// Tenant is a security dependency rather than a primary UI record. Its native
// membership writes must still reset authorization after narrowing registration.
$user = $em->createEntity('User', [
    'emailAddress' => 'live-record-membership@example.test', 'firstName' => 'Scoped',
    'lastName' => 'Viewer', 'type' => 'regular', 'isActive' => true,
]);
$resetCount = static function () use ($pdo, $tenant): int {
    $query = $pdo->prepare('SELECT intent FROM live_record_outbox WHERE tenant_id = ?');
    $query->execute([$tenant->getId()]);
    return count(array_filter($query->fetchAll(PDO::FETCH_COLUMN), fn ($json) =>
        json_decode($json, true)['kind'] === 'access'));
};
$before = $resetCount();
$em->getRelation($tenant, 'users')->relate($user);
$check($resetCount() > $before, 'Tenant membership grant did not emit a permission reset.');
$before = $resetCount();
for ($i = 0; $i < 10; $i++) $em->getRelation($tenant, 'users')->relate($user);
$check($resetCount() === $before, 'Idempotent membership reconciliation emitted permission resets.');
$em->getRelation($tenant, 'users')->unrelate($user);
$check($resetCount() > $before, 'Tenant membership revocation did not emit a permission reset.');
$before = $resetCount();
for ($i = 0; $i < 10; $i++) $em->getRelation($tenant, 'users')->unrelate($user);
$check($resetCount() === $before, 'Removing an absent membership emitted permission resets.');
fwrite(STDOUT, "Live record application runtime: $checks checks passed.\n");
