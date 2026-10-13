<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Espo\Core\Exceptions\{Conflict, NotFound};
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureCredits\Accounting\{GrantInput, RecordId};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Command, Connections};
use PDO;
use PDOException;

/** Service-only creation claims and original-provider bindings. No funding or external I/O under locks. */
final class CreationStore
{
    public function __construct(private Accounts $accounts, private Crypt $crypt, private Connections $connections) {}

    public function customer(string $tenant, string $obligation, #[\SensitiveParameter] array $profile): array
    {
        $normalized = Command::customer($profile, 'crm-credit-00000000000000000')->body;
        unset($normalized['externalReference']);
        return $this->claim($tenant, $obligation, 'customer', 'customer', $normalized,
            static fn (string $reference, array $accepted, ?string $customer) => Command::customer($profile, $reference));
    }

    public function charge(string $tenant, string $obligation, string $customerCreation, string $billingType, string $dueDate): array
    {
        GrantInput::identity($customerCreation); GrantInput::timestamp($dueDate . ' 00:00:00');
        if (!in_array($billingType, ['PIX', 'BOLETO', 'CREDIT_CARD', 'UNDEFINED'], true)) throw new \InvalidArgumentException('Unsupported Asaas payment method.');
        return $this->claim($tenant, $obligation, 'charge', $obligation,
            ['customerCreation' => $customerCreation, 'billingType' => $billingType, 'dueDate' => $dueDate],
            static fn (string $reference, array $accepted, ?string $customer) => Command::charge(
                OfferTerms::fromSnapshot((object) $accepted['terms']), $customer, $reference, $billingType, $dueDate), $customerCreation);
    }

    private function claim(string $tenant, string $obligation, string $kind, string $source, array $input, callable $command, ?string $customerCreation = null): array
    {
        GrantInput::identity($obligation);
        return $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant, $obligation, $kind, $source, $input, $command, $customerCreation): array {
            $q = $pdo->prepare('SELECT * FROM credit_billing_obligation WHERE id=? AND tenant_id=?'); $q->execute([$obligation, $tenant]);
            $parent = $q->fetch(PDO::FETCH_ASSOC);
            if (!$parent || $parent['deleted']) throw new NotFound('Billing obligation is unavailable.');
            $accepted = $this->accounts->originalAcceptance($parent);
            if ($accepted['provider'] !== 'asaas') throw new Conflict('Originating billing provider has no creation adapter.');
            $kind = $kind === 'charge' ? ($accepted['terms']['operation'] === 'purchase' ? 'payment' : 'subscription') : $kind;
            $inputHash = static fn (string $environment): string => self::hash(['creation-input-v1', $tenant, 'asaas', $accepted['integrationId'], $environment, $kind, $source,
                $kind === 'customer' ? null : $parent['acceptance_hash'], $input]);
            $q = $pdo->prepare('SELECT * FROM credit_billing_creation WHERE tenant_id=? AND provider=? AND integration_id=? AND kind=? AND source_key=?');
            $q->execute([$tenant, 'asaas', $accepted['integrationId'], $kind, $source]);
            if ($existing = $q->fetch(PDO::FETCH_ASSOC)) {
                $this->verify($existing);
                if ($existing['input_hash'] !== $inputHash($existing['environment'])) throw new Conflict('Creation identity already has different input.');
                return ['receipt' => $this->receipt($existing), 'dispatchAllowed' => false];
            }
            // Original receipts need neither current credentials nor current sales configuration.
            $connection = $this->connections->get($accepted['integrationId']);
            $hash = $inputHash($connection->environment);
            if (!$connection->creationEnabled) throw new Conflict('Asaas creation is disabled.');
            if (!in_array($parent['state'], ['accepted', 'creating'], true)) throw new Conflict('Billing obligation cannot begin new external creation.');
            $customer = null;
            if ($customerCreation !== null) {
                $q = $pdo->prepare('SELECT * FROM credit_billing_creation WHERE id=? AND tenant_id=?'); $q->execute([$customerCreation, $tenant]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new NotFound('Original billing customer is unavailable.');
                $this->verify($row);
                if ($row['state'] !== 'bound' || $row['kind'] !== 'customer' || $row['provider'] !== 'asaas' ||
                    $row['integration_id'] !== $connection->id || $row['environment'] !== $connection->environment) throw new Conflict('Billing customer binding does not match the obligation.');
                $customer = $row['external_id'];
                if ($input['dueDate'] < substr($now, 0, 10)) throw new Conflict('A new billing creation cannot use a past due date.');
            }
            $id = RecordId::generate(); $reference = 'crm-credit-' . $id;
            $request = $command($reference, $accepted, $customer);
            $request->json(); // Validate exact transport encoding before committing any creation claim.
            $row = ['id' => $id, 'tenant_id' => $tenant, 'obligation_id' => $obligation, 'provider' => 'asaas',
                'integration_id' => $connection->id, 'environment' => $connection->environment, 'kind' => $kind,
                'source_key' => $source, 'input_hash' => $hash, 'external_reference' => $reference,
                'request_hash' => self::hash($request->body),
                'request_ciphertext' => $this->crypt->encrypt(json_encode($request->body, JSON_THROW_ON_ERROR)),
                'state' => 'creating', 'created_at' => $now, 'modified_at' => $now];
            $row['binding_hash'] = $this->bindingHash($row);
            $pdo->prepare('INSERT INTO credit_billing_creation
                (id, tenant_id, obligation_id, provider, integration_id, environment, kind, source_key, input_hash,
                 external_reference, request_hash, request_ciphertext, state, created_at, modified_at, binding_hash)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_values($row));
            $pdo->prepare("UPDATE credit_billing_obligation SET state='creating' WHERE id=? AND tenant_id=?")->execute([$obligation, $tenant]);
            // Only this committed caller receives permission to POST. A crash leaves an unresolved claim.
            return ['receipt' => $this->receipt($row), 'dispatchAllowed' => true, 'command' => $request, 'connection' => $connection];
        });
    }

    /** Lookup-only recovery, including after sales have been disabled or tenant country has changed. */
    public function recovery(string $tenant, string $id): array
    {
        GrantInput::identity($id);
        return $this->accounts->withTenantLock($tenant, function (PDO $pdo) use ($tenant, $id): array {
            $row = $this->load($pdo, $tenant, $id); $command = $this->verify($row);
            if ($row['state'] === 'bound') return ['receipt' => $this->receipt($row), 'lookupAllowed' => false];
            $connection = $this->connections->get($row['integration_id']);
            if ($connection->environment !== $row['environment']) throw new Conflict('Original integration environment changed.');
            return ['receipt' => $this->receipt($row), 'lookupAllowed' => true, 'connection' => $connection, 'command' => $command];
        });
    }

    /** Trusted adapter result only; not exposed to the HTTP client or webhook body. */
    public function record(string $tenant, string $id, array $result): array
    {
        GrantInput::identity($id);
        if (!in_array($result['state'] ?? null, ['bound', 'unknown'], true)) throw new \InvalidArgumentException('Invalid creation outcome.');
        try {
            return $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant, $id, $result): array {
                $row = $this->load($pdo, $tenant, $id); $this->verify($row);
                if ($row['state'] === 'bound') {
                    if ($result['state'] === 'bound' && ($result['externalId'] ?? null) !== $row['external_id']) throw new Conflict('External binding is immutable.');
                    return $this->receipt($row); // A late unknown result cannot undo recovered success.
                }
                if ($result['state'] === 'bound') {
                    if (!is_string($result['externalId'] ?? null)) throw new \InvalidArgumentException('External reference required.');
                    Command::externalId($row['kind'], $result['externalId']);
                    $row['external_id'] = $result['externalId']; $row['external_hash'] = hash('sha256', $result['externalId']);
                    $row['state'] = 'bound'; $row['result_hash'] = self::hash([$row['binding_hash'], $row['external_id']]);
                    $pdo->prepare("UPDATE credit_billing_creation SET state='bound', external_id=?, external_hash=?, result_hash=?, modified_at=? WHERE id=? AND tenant_id=?")
                        ->execute([$row['external_id'], $row['external_hash'], $row['result_hash'], $now, $id, $tenant]);
                    $state = $row['kind'] === 'customer' ? 'creating' : 'pending';
                } else {
                    $row['state'] = 'unknown';
                    $pdo->prepare("UPDATE credit_billing_creation SET state='unknown', modified_at=? WHERE id=? AND tenant_id=?")->execute([$now, $id, $tenant]);
                    $state = 'unknown';
                }
                $q = $pdo->prepare('SELECT * FROM credit_billing_obligation WHERE id=? AND tenant_id=?'); $q->execute([$row['obligation_id'], $tenant]);
                $parent = $q->fetch(PDO::FETCH_ASSOC);
                if (!$parent || $parent['deleted'] || !in_array($parent['state'], ['creating', 'unknown'], true)) throw new Conflict('External creation lost its original obligation.');
                $accepted = $this->accounts->originalAcceptance($parent);
                if ($accepted['provider'] !== $row['provider'] || $accepted['integrationId'] !== $row['integration_id']) throw new Conflict('Original provider binding mismatch.');
                $pdo->prepare('UPDATE credit_billing_obligation SET state=? WHERE id=? AND tenant_id=?')->execute([$state, $row['obligation_id'], $tenant]);
                return $this->receipt($row);
            });
        } catch (PDOException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) throw new Conflict('External object is already bound or its binding could not be recorded.');
            throw $e;
        }
    }

    /** Internal payment collaborator; validates the encrypted original request and immutable binding. */
    public function verify(array $row): Command
    {
        if ($row['deleted'] ?? false) throw new Conflict('Deleted billing creation.');
        if ($row['provider'] !== 'asaas' || !in_array($row['state'], ['creating', 'unknown', 'bound'], true) ||
            $row['binding_hash'] !== $this->bindingHash($row)) throw new Conflict('Billing creation binding is inconsistent.');
        try {
            $body = json_decode($this->crypt->decrypt($row['request_ciphertext']), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body) || self::hash($body) !== $row['request_hash'] || ($body['externalReference'] ?? null) !== $row['external_reference']) throw new \InvalidArgumentException();
            $command = Command::restore($row['kind'], $body);
            if ($row['state'] === 'bound') {
                Command::externalId($row['kind'], $row['external_id']);
                if (hash('sha256', $row['external_id']) !== $row['external_hash'] || self::hash([$row['binding_hash'], $row['external_id']]) !== $row['result_hash']) throw new \InvalidArgumentException();
            } elseif (($row['external_id'] ?? null) !== null || ($row['external_hash'] ?? null) !== null || ($row['result_hash'] ?? null) !== null) throw new \InvalidArgumentException();
            return $command;
        } catch (\Throwable) { throw new Conflict('Stored billing creation request is unavailable or inconsistent.'); }
    }

    private function load(PDO $pdo, string $tenant, string $id): array
    {
        $q = $pdo->prepare('SELECT * FROM credit_billing_creation WHERE id=? AND tenant_id=?'); $q->execute([$id, $tenant]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Billing creation is unavailable.');
        return $row;
    }

    private function bindingHash(array $row): string
    {
        return self::hash(array_intersect_key($row, array_flip(['id', 'tenant_id', 'obligation_id', 'provider', 'integration_id',
            'environment', 'kind', 'source_key', 'input_hash', 'external_reference', 'request_hash', 'created_at'])));
    }

    private function receipt(array $row): array
    {
        return ['creationId' => $row['id'], 'tenantId' => $row['tenant_id'], 'obligationId' => $row['obligation_id'],
            'provider' => $row['provider'], 'integrationId' => $row['integration_id'], 'environment' => $row['environment'],
            'kind' => $row['kind'], 'state' => $row['state'], 'externalId' => $row['external_id'] ?? null, 'dispatchAllowed' => false];
    }

    private static function hash(array $input): string
    {
        $canonical = GrantInput::canonical(json_decode(json_encode($input, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR));
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
