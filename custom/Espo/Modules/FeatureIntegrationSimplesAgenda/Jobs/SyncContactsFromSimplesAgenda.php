<?php

namespace Espo\Modules\FeatureIntegrationSimplesAgenda\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\Modules\FeatureIntegrationSimplesAgenda\Services\SimplesAgendaApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Scheduled job to sync contacts from SimplesAgenda to EspoCRM.
 * Downloads the XLS export and upserts SimplesAgendaCliente + Contact.
 *
 * Uses bulk operations: pre-fetches existing records, processes in batched
 * transactions to minimise per-row DB round-trips.
 */
class SyncContactsFromSimplesAgenda implements JobDataLess
{
    private const XLS_COL_NOME = 1;
    private const XLS_COL_CPF = 2;
    private const XLS_COL_CNPJ = 3;
    private const XLS_COL_RG = 4;
    private const XLS_COL_DATA_NASCIMENTO = 5;
    private const XLS_COL_CEP = 6;
    private const XLS_COL_ENDERECO = 7;
    private const XLS_COL_NUMERO = 8;
    private const XLS_COL_COMPLEMENTO = 9;
    private const XLS_COL_REFERENCIA = 10;
    private const XLS_COL_BAIRRO = 11;
    private const XLS_COL_ESTADO = 12;
    private const XLS_COL_CIDADE = 13;
    private const XLS_COL_OBSERVACAO = 14;
    private const XLS_COL_DATA_CADASTRO = 15;
    private const XLS_COL_DATA_ATUALIZACAO = 16;
    private const XLS_COL_CREDITO = 17;
    private const XLS_COL_TAGS = 18;
    private const XLS_COL_COMO_CONHECEU = 19;
    private const XLS_COL_COD_CLIENTE = 20;
    private const XLS_COL_CONTATOS = 21;

    /** Rows per transaction batch */
    private const BATCH_SIZE = 200;

    public function __construct(
        private EntityManager $entityManager,
        private CredentialResolver $credentialResolver,
        private SimplesAgendaApiClient $apiClient,
        private Log $log
    ) {}

    public function run(): void
    {
        try {
            $credentials = $this->getActiveCredentials();

            if (count($credentials) === 0) {
                $this->log->warning('SyncContactsFromSimplesAgenda: No active SimplesAgenda credentials found.');
                return;
            }

            foreach ($credentials as $credential) {
                $this->syncCredential($credential);
            }
        } catch (\Throwable $e) {
            $this->log->error('SyncContactsFromSimplesAgenda: Job failed - ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * @return Entity[]
     */
    private function getActiveCredentials(): array
    {
        $credentialType = $this->entityManager
            ->getRDBRepository('CredentialType')
            ->where(['code' => 'simplesAgenda'])
            ->findOne();

        if (!$credentialType) {
            return [];
        }

        $list = $this->entityManager
            ->getRDBRepository('Credential')
            ->where([
                'credentialTypeId' => $credentialType->getId(),
                'isActive' => true,
            ])
            ->find();

        return iterator_to_array($list);
    }

    private function syncCredential(Entity $credential): void
    {
        $credentialId = $credential->getId();
        $credentialName = $credential->get('name') ?? $credentialId;

        try {
            $config = $this->credentialResolver->resolve($credentialId);
            $username = $config->username ?? null;
            $password = $config->password ?? null;

            if (!$username || !$password) {
                throw new \Exception('Missing username or password in credential config');
            }

            $usernameField = $config->usernameField ?? 'login';
            $passwordField = $config->passwordField ?? 'senha';
            $empresa = $config->empresa ?? null;

            $cookieFile = $this->apiClient->login($username, $password, $usernameField, $passwordField, $empresa);

            try {
                $xlsContent = $this->apiClient->exportClientes($cookieFile);
            } finally {
                @unlink($cookieFile);
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'sa_export_');
            if ($tempFile === false) {
                throw new \Exception('Failed to create temp file');
            }

            try {
                $this->validateXlsContent($xlsContent);
                file_put_contents($tempFile, $xlsContent);
                $teamsIds = $credential->getLinkMultipleIdList('teams') ?? [];
                $assignedUserId = $credential->get('assignedUserId');
                $this->processXls($tempFile, $credentialId, $credentialName, $teamsIds, $assignedUserId);
            } finally {
                @unlink($tempFile);
            }
        } catch (\Exception $e) {
            $this->log->error("SyncContactsFromSimplesAgenda: {$credentialName} failed - " . $e->getMessage());
        }
    }

    /**
     * Validate that the response looks like XLS/XLSX, not HTML (e.g. login page).
     */
    private function validateXlsContent(string $content): void
    {
        $trimmed = ltrim($content);
        if (str_starts_with($trimmed, '<') || str_starts_with($trimmed, '<!') || str_starts_with($trimmed, '<?xml')) {
            throw new \Exception(
                'Export returned HTML/XML instead of XLS (login may have failed or session expired). ' .
                'Check SimplesAgenda username/password, and verify the form field names (usernameField, passwordField) match the login page.'
            );
        }
    }

    // ─── BULK PROCESSING ───────────────────────────────────────────────

    /**
     * Parse XLS, then batch-upsert in chunks.
     *
     * @param array<string> $teamsIds
     * @return array{upserted: int, errors: int, skipped: int}
     */
    private function processXls(string $filePath, string $credentialId, string $credentialName, array $teamsIds = [], ?string $assignedUserId = null): array
    {
        $stats = ['upserted' => 0, 'errors' => 0, 'skipped' => 0];

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName('CLIENTES') ?? $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        if ($highestRow < 2) {
            $this->log->warning("SyncContactsFromSimplesAgenda: {$credentialName} - No data rows (header only or empty sheet)");
            return $stats;
        }

        // ── Phase 1: Parse all rows into memory ──
        $allRows = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $codClienteVal = $this->getCellValue($sheet, self::XLS_COL_COD_CLIENTE, $row);
            if ($codClienteVal === null || $codClienteVal === '') {
                $stats['skipped']++;
                continue;
            }

            $allRows[] = [
                'rowNum' => $row,
                'codCliente' => (int) $codClienteVal,
                'name' => $this->getCellValue($sheet, self::XLS_COL_NOME, $row),
                'cpf' => $this->getCellValue($sheet, self::XLS_COL_CPF, $row),
                'cnpj' => $this->getCellValue($sheet, self::XLS_COL_CNPJ, $row),
                'rg' => $this->getCellValue($sheet, self::XLS_COL_RG, $row),
                'dataNascimento' => $this->getCellValue($sheet, self::XLS_COL_DATA_NASCIMENTO, $row),
                'cep' => $this->getCellValue($sheet, self::XLS_COL_CEP, $row),
                'endereco' => $this->getCellValue($sheet, self::XLS_COL_ENDERECO, $row),
                'numero' => $this->getCellValue($sheet, self::XLS_COL_NUMERO, $row),
                'complemento' => $this->getCellValue($sheet, self::XLS_COL_COMPLEMENTO, $row),
                'referencia' => $this->getCellValue($sheet, self::XLS_COL_REFERENCIA, $row),
                'bairro' => $this->getCellValue($sheet, self::XLS_COL_BAIRRO, $row),
                'estado' => $this->getCellValue($sheet, self::XLS_COL_ESTADO, $row),
                'cidade' => $this->getCellValue($sheet, self::XLS_COL_CIDADE, $row),
                'observacao' => $this->getCellValue($sheet, self::XLS_COL_OBSERVACAO, $row),
                'dataCadastro' => $this->getCellValue($sheet, self::XLS_COL_DATA_CADASTRO, $row),
                'dataAtualizacao' => $this->getCellValue($sheet, self::XLS_COL_DATA_ATUALIZACAO, $row),
                'credito' => $this->getCellValue($sheet, self::XLS_COL_CREDITO, $row),
                'tags' => $this->getCellValue($sheet, self::XLS_COL_TAGS, $row),
                'comoConheceu' => $this->getCellValue($sheet, self::XLS_COL_COMO_CONHECEU, $row),
                'contatos' => $this->normalizePhone($this->getCellValue($sheet, self::XLS_COL_CONTATOS, $row)),
            ];
        }

        // Free spreadsheet memory
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);

        $totalParsed = count($allRows);

        if ($totalParsed === 0) {
            return $stats;
        }

        // ── Phase 2: Process in batches ──
        $chunks = array_chunk($allRows, self::BATCH_SIZE);
        $chunkCount = count($chunks);

        foreach ($chunks as $chunkIndex => $chunk) {
            try {
                $chunkStats = $this->processChunk($chunk, $credentialId, $teamsIds, $assignedUserId);
                $stats['upserted'] += $chunkStats['upserted'];
                $stats['errors'] += $chunkStats['errors'];
            } catch (\Throwable $e) {
                $stats['errors'] += count($chunk);
                $this->log->error(
                    "SyncContactsFromSimplesAgenda: {$credentialName} - Batch " . ($chunkIndex + 1) .
                    " failed: " . $e->getMessage()
                );
            }
        }

        return $stats;
    }

    /**
     * Process a chunk of parsed rows in a single transaction.
     *
     * 1. Batch-fetch existing SimplesAgendaCliente by codCliente
     * 2. Batch-fetch existing Contacts by phone numbers
     * 3. Upsert all rows
     *
     * @param array<array<string,mixed>> $rows
     * @param array<string> $teamsIds
     * @return array{upserted: int, errors: int}
     */
    private function processChunk(array $rows, string $credentialId, array $teamsIds, ?string $assignedUserId): array
    {
        $stats = ['upserted' => 0, 'errors' => 0];

        // ── Collect lookup keys ──
        $codClientes = array_map(fn($r) => $r['codCliente'], $rows);
        $phones = [];
        foreach ($rows as $row) {
            $phone = $row['contatos'] ?? null;
            if ($phone) {
                $phones[] = $phone;
            }
        }

        // ── Batch-fetch existing SimplesAgendaCliente ──
        $existingClientesMap = $this->batchFetchClientes($codClientes, $credentialId);

        // ── Batch-fetch existing Contacts by phone (already normalised to +55…) ──
        $allPhones = array_unique($phones);
        $existingContactsByPhone = $this->batchFetchContactsByPhone($allPhones);

        // ── Upsert in a transaction ──
        $tm = $this->entityManager->getTransactionManager();
        $tm->start();

        try {
            $now = date('Y-m-d H:i:s');

            foreach ($rows as $row) {
                try {
                    $codCliente = $row['codCliente'];
                    $existing = $existingClientesMap[$codCliente] ?? null;

                    $data = [
                        'name' => $row['name'],
                        'codCliente' => $codCliente,
                        'cpf' => $row['cpf'],
                        'cnpj' => $row['cnpj'],
                        'rg' => $row['rg'],
                        'dataNascimento' => $row['dataNascimento'],
                        'cep' => $row['cep'],
                        'endereco' => $row['endereco'],
                        'numero' => $row['numero'],
                        'complemento' => $row['complemento'],
                        'referencia' => $row['referencia'],
                        'bairro' => $row['bairro'],
                        'estado' => $row['estado'],
                        'cidade' => $row['cidade'],
                        'observacao' => $row['observacao'],
                        'dataCadastro' => $row['dataCadastro'],
                        'dataAtualizacao' => $row['dataAtualizacao'],
                        'credito' => $row['credito'],
                        'tags' => $row['tags'],
                        'comoConheceu' => $row['comoConheceu'],
                        'contatos' => $row['contatos'],
                        'credentialId' => $credentialId,
                        'lastSyncedAt' => $now,
                        'syncStatus' => 'synced',
                    ];

                    if (!empty($teamsIds)) {
                        $data['teamsIds'] = $teamsIds;
                    }
                    if ($assignedUserId !== null && $assignedUserId !== '') {
                        $data['assignedUserId'] = $assignedUserId;
                    }

                    if ($existing) {
                        $existing->set($data);
                        $this->entityManager->saveEntity($existing, ['silent' => true]);
                        $clienteEntity = $existing;
                    } else {
                        $clienteEntity = $this->entityManager->createEntity('SimplesAgendaCliente', $data, ['silent' => true]);
                    }

                    // ── Link to Contact ──
                    $this->linkContact($clienteEntity, $data, $existingContactsByPhone, $teamsIds, $assignedUserId);

                    $stats['upserted']++;
                } catch (\Exception $e) {
                    $stats['errors']++;
                    $rowNum = $row['rowNum'] ?? '?';
                    $this->log->warning("SyncContactsFromSimplesAgenda: Row {$rowNum} error - " . $e->getMessage());
                }
            }

            $tm->commit();
        } catch (\Throwable $e) {
            $tm->rollback();
            throw $e;
        }

        return $stats;
    }

    /**
     * Batch-fetch existing SimplesAgendaCliente records by codCliente list.
     *
     * @param int[] $codClientes
     * @return array<int, Entity> keyed by codCliente
     */
    private function batchFetchClientes(array $codClientes, string $credentialId): array
    {
        if (empty($codClientes)) {
            return [];
        }

        $collection = $this->entityManager
            ->getRDBRepository('SimplesAgendaCliente')
            ->where([
                'codCliente' => $codClientes,
                'credentialId' => $credentialId,
            ])
            ->find();

        $map = [];
        foreach ($collection as $entity) {
            $map[$entity->get('codCliente')] = $entity;
        }

        return $map;
    }

    /**
     * Batch-fetch Contacts by phone numbers (both raw and normalised).
     *
     * @param string[] $phones
     * @return array<string, Entity> keyed by phone number
     */
    private function batchFetchContactsByPhone(array $phones): array
    {
        if (empty($phones)) {
            return [];
        }

        $collection = $this->entityManager
            ->getRDBRepository('Contact')
            ->where(['phoneNumber' => $phones])
            ->find();

        $map = [];
        foreach ($collection as $contact) {
            $phone = $contact->get('phoneNumber');
            if ($phone) {
                $map[$phone] = $contact;
            }
        }

        return $map;
    }

    /**
     * Link a SimplesAgendaCliente to a Contact (find or create).
     *
     * @param array<string,mixed> $data
     * @param array<string, Entity> $contactsByPhone Pre-fetched contacts map
     * @param array<string> $teamsIds
     */
    private function linkContact(
        Entity $clienteEntity,
        array $data,
        array &$contactsByPhone,
        array $teamsIds,
        ?string $assignedUserId
    ): void {
        $contactId = $clienteEntity->get('contactId');
        $phone = $data['contatos'] ?? null; // already normalised to +55…

        if (!$contactId && $phone) {
            // Try finding from pre-fetched map
            $contact = $contactsByPhone[$phone] ?? null;

            // Fallback: try DB query for deleted contacts
            if (!$contact) {
                $contact = $this->findDeletedContactByPhone($phone);
            }

            if ($contact) {
                $clienteEntity->set('contactId', $contact->getId());
                $this->entityManager->saveEntity($clienteEntity, ['silent' => true]);
                $this->updateContactFromRow($contact, $data, $teamsIds, $assignedUserId);
                $contactsByPhone[$phone] = $contact;
            } else {
                $contact = $this->createContactFromRow($data, $teamsIds, $assignedUserId);
                if ($contact) {
                    $clienteEntity->set('contactId', $contact->getId());
                    $this->entityManager->saveEntity($clienteEntity, ['silent' => true]);
                    $contactsByPhone[$phone] = $contact;
                }
            }
        } elseif ($contactId) {
            $contact = $this->entityManager->getEntityById('Contact', $contactId);
            if ($contact) {
                $this->updateContactFromRow($contact, $data, $teamsIds, $assignedUserId);
            }
        }
    }

    // ─── HELPERS ────────────────────────────────────────────────────────

    private function getCellValue($sheet, int $col, int $row): ?string
    {
        $value = $sheet->getCell([$col, $row])->getValue();
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value) && $value == (int) $value) {
            return (string) (int) $value;
        }
        return trim((string) $value);
    }

    /**
     * Normalise a Brazilian phone number to E.164 format (+55XXXXXXXXXX or +55XXXXXXXXXXX).
     *
     * Accepts common formats from SimplesAgenda:
     *   (11) 98765-4321 → +5511987654321
     *   11987654321      → +5511987654321
     *   5511987654321    → +5511987654321
     *   +5511987654321   → +5511987654321
     *
     * Valid Brazilian numbers have:
     *   - DDD (2 digits, 11–99) + 8-digit landline  → 10 digits total
     *   - DDD (2 digits, 11–99) + 9-digit mobile    → 11 digits total
     *
     * Returns null for anything that doesn't resolve to a valid number.
     */
    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        // Strip everything except digits
        $digits = preg_replace('/\D/', '', $phone);

        if ($digits === '' || $digits === null) {
            return null;
        }

        // Remove leading country code if present
        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        $len = strlen($digits);

        // 10 digits = DDD(2) + landline(8)
        // 11 digits = DDD(2) + mobile(9)
        if ($len !== 10 && $len !== 11) {
            return null;
        }

        // DDD must be 11–99
        $ddd = (int) substr($digits, 0, 2);
        if ($ddd < 11 || $ddd > 99) {
            return null;
        }

        return '+55' . $digits;
    }

    /**
     * Search for a deleted Contact by phone and restore it.
     */
    private function findDeletedContactByPhone(string $phone): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('Contact')
            ->where(['phoneNumber' => $phone])
            ->withDeleted()
            ->build();

        $contact = $this->entityManager
            ->getRDBRepository('Contact')
            ->clone($query)
            ->findOne();

        if ($contact) {
            $this->entityManager->getRDBRepository('Contact')->restoreDeleted($contact->getId());
            return $this->entityManager->getEntityById('Contact', $contact->getId());
        }

        return null;
    }

    /**
     * @param array<string> $teamsIds
     */
    private function updateContactFromRow(Entity $contact, array $data, array $teamsIds = [], ?string $assignedUserId = null): void
    {
        $name = $data['name'] ?? '';
        $nameParts = explode(' ', $name, 2);

        if (!$contact->get('firstName') && isset($nameParts[0])) {
            $contact->set('firstName', $nameParts[0]);
        }
        if (!$contact->get('lastName') && isset($nameParts[1])) {
            $contact->set('lastName', $nameParts[1]);
        } elseif (!$contact->get('lastName')) {
            $contact->set('lastName', $nameParts[0] ?? 'Cliente');
        }
        if (!$contact->get('phoneNumber') && !empty($data['contatos'])) {
            $contact->set('phoneNumber', $data['contatos']);
        }
        if (!$contact->get('description') && !empty($data['observacao'])) {
            $contact->set('description', $data['observacao']);
        }
        if (!$contact->get('addressStreet') && !empty($data['endereco'])) {
            $contact->set('addressStreet', $data['endereco']);
        }
        if (!$contact->get('addressCity') && !empty($data['cidade'])) {
            $contact->set('addressCity', $data['cidade']);
        }
        if (!$contact->get('addressState') && !empty($data['estado'])) {
            $contact->set('addressState', $data['estado']);
        }
        if (!$contact->get('addressPostalCode') && !empty($data['cep'])) {
            $contact->set('addressPostalCode', $data['cep']);
        }

        if (!empty($teamsIds)) {
            $contact->set('teamsIds', $teamsIds);
        }
        if ($assignedUserId !== null && $assignedUserId !== '') {
            $contact->set('assignedUserId', $assignedUserId);
        }

        $this->entityManager->saveEntity($contact, ['silent' => true]);
    }

    /**
     * @param array<string> $teamsIds
     */
    private function createContactFromRow(array $data, array $teamsIds = [], ?string $assignedUserId = null): ?Entity
    {
        $name = $data['name'] ?? 'Cliente';
        $nameParts = explode(' ', $name, 2);
        $phone = $data['contatos'] ?? null;

        if (!$phone) {
            return null;
        }

        $contactData = [
            'firstName' => $nameParts[0] ?? 'Cliente',
            'lastName' => $nameParts[1] ?? 'SimplesAgenda',
            'phoneNumber' => $phone,
            'description' => $data['observacao'] ?? 'Importado do SimplesAgenda',
            'addressStreet' => $data['endereco'] ?? null,
            'addressCity' => $data['cidade'] ?? null,
            'addressState' => $data['estado'] ?? null,
            'addressPostalCode' => $data['cep'] ?? null,
        ];

        if (!empty($teamsIds)) {
            $contactData['teamsIds'] = $teamsIds;
        }
        if ($assignedUserId !== null && $assignedUserId !== '') {
            $contactData['assignedUserId'] = $assignedUserId;
        }

        return $this->entityManager->createEntity('Contact', $contactData, ['silent' => true]);
    }
}
