<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Espo\Core\Exceptions\{Conflict, NotFound, ServiceUnavailable};
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Billing\Asaas\{Command, Connections, PaymentVerification, Transport};
use PDO;

/** Authorized UI reads. Never creates provider resources or changes funding from a browser claim. */
final class Checkout
{
    public function __construct(
        private Accounts $accounts, private CreationStore $creations,
        private Connections $connections, private Transport $transport,
    ) {}

    public function context(string $tenant): array
    {
        $context = $this->accounts->context($tenant);
        return $context + $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant): array {
            $q = $pdo->prepare('SELECT * FROM credit_billing_obligation WHERE tenant_id=? AND deleted=FALSE ORDER BY created_at DESC, id DESC LIMIT 26');
            $q->execute([$tenant]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            return ['earliestDueDate' => substr($now, 0, 10), 'hasMoreCheckouts' => count($rows) > 25,
                'checkouts' => array_map(fn (array $row) => ['state' => $row['state'], 'acceptance' => $this->accounts->originalAcceptance($row)], array_slice($rows, 0, 25))];
        });
    }

    public function read(string $tenant, string $obligation): array
    {
        return $this->snapshot($tenant, $obligation)['view'];
    }

    private function snapshot(string $tenant, string $obligation): array
    {
        GrantInput::identity($obligation);
        return $this->accounts->withTenantLock($tenant, function (PDO $pdo, string $now) use ($tenant, $obligation): array {
            $q = $pdo->prepare('SELECT * FROM credit_billing_obligation WHERE tenant_id=? AND id=?');
            $q->execute([$tenant, $obligation]); $parent = $q->fetch(PDO::FETCH_ASSOC);
            if (!$parent || $parent['deleted']) throw new NotFound('Checkout is unavailable.');
            $accepted = $this->accounts->originalAcceptance($parent);
            $q = $pdo->prepare("SELECT * FROM credit_billing_creation WHERE tenant_id=? AND provider=? AND integration_id=?
                AND ((kind='customer' AND source_key='customer') OR (kind IN ('payment','subscription') AND obligation_id=?))");
            $q->execute([$tenant, $accepted['provider'], $accepted['integrationId'], $obligation]);
            $customer = null; $customerExternal = null; $charge = null; $command = null; $subscription = null;
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $original = $this->creations->verify($row);
                if ($row['kind'] === 'customer') {
                    if ($customer !== null) throw new Conflict('Ambiguous checkout customer.');
                    $customer = $this->summary($row);
                    $customerExternal = $row['external_id'];
                } else {
                    if ($charge !== null || $row['source_key'] !== $obligation ||
                        $row['kind'] !== ($accepted['terms']['operation'] === 'purchase' ? 'payment' : 'subscription')) throw new Conflict('Ambiguous checkout charge.');
                    $charge = $this->summary($row); $command = $original;
                    if ($row['kind'] === 'subscription' && $row['state'] === 'bound') $subscription = Subscriptions::projection($parent, $row);
                }
            }
            if ($charge && (!$customer || $customer['state'] !== 'bound' || $customer['environment'] !== $charge['environment'] ||
                ($command->body['customer'] ?? null) !== $customerExternal)) throw new Conflict('Checkout customer binding is unavailable.');
            // The decrypted command stays internal. In particular, never return payer data to the SPA.
            return ['view' => ['tenantId' => $tenant, 'obligationId' => $obligation, 'state' => $parent['state'],
                'acceptance' => $accepted, 'customer' => $customer, 'charge' => $charge, 'subscription' => $subscription,
                'earliestDueDate' => substr($now, 0, 10)], 'command' => $command];
        });
    }

    private function summary(array $row): array
    {
        return array_intersect_key(['creationId' => $row['id']] + $row, array_flip(['creationId', 'kind', 'state', 'environment']));
    }

    /** Bounded explicit handoff lookup, not continuous status polling or funding authority. */
    public function payment(string $tenant, string $obligation): array
    {
        $snapshot = $this->snapshot($tenant, $obligation); $view = $snapshot['view']; $charge = $view['charge'];
        $command = $snapshot['command'];
        if (!$charge || $charge['state'] !== 'bound' || !in_array($view['state'], ['pending', 'active', 'completed'], true)) throw new Conflict('Checkout charge is not ready.');
        $binding = $this->creations->recovery($tenant, $charge['creationId'])['receipt'];
        $connection = $this->connections->get($binding['integrationId']);
        if ($connection->environment !== $binding['environment']) throw new Conflict('Original integration environment changed.');
        $id = $binding['externalId'];
        if ($command->kind === 'subscription') {
            $due = $command->body['nextDueDate'];
            $response = $this->transport->send($connection, 'GET', '/payments', null, [
                'subscription' => $id, 'customer' => $command->body['customer'], 'dueDate[ge]' => $due, 'dueDate[le]' => $due, 'offset' => 0, 'limit' => 2,
            ]);
            $data = $response->data;
            if ($response->status !== 200 || !is_array($data) || ($data['object'] ?? null) !== 'list' ||
                ($data['hasMore'] ?? null) !== false || ($data['offset'] ?? null) !== '0' ||
                !is_array($data['data'] ?? null) || !array_is_list($data['data']) || ($data['totalCount'] ?? null) !== (string) count($data['data'])) {
                throw new ServiceUnavailable('Checkout payment discovery is unresolved.');
            }
            if (count($data['data']) !== 1 || !is_array($data['data'][0])) throw new Conflict('Checkout requires one original-period payment.');
            $id = $data['data'][0]['id'] ?? null;
            if (!is_string($id)) throw new Conflict('Checkout payment identity is unavailable.');
            try { Command::externalId('payment', $id); } catch (\InvalidArgumentException) { throw new Conflict('Checkout payment identity is unavailable.'); }
        }
        $response = $this->transport->send($connection, 'GET', '/payments/' . $id);
        if ($response->status !== 200 || !is_array($response->data)) throw new ServiceUnavailable('Checkout payment lookup is unresolved.');
        $verified = PaymentVerification::verify($response->data, $id, $command, $binding['externalId']);
        if ($verified['originalDueDate'] !== ($command->body['nextDueDate'] ?? $command->body['dueDate'])) throw new Conflict('Checkout payment belongs to another period.');
        $url = null;
        $state = $verified['review'] ? 'review_required' : ($verified['paid'] ? 'paid' : 'pending');
        if (!$verified['review'] && !$verified['paid'] && in_array($verified['status'], ['PENDING', 'OVERDUE'], true)) {
            $url = self::invoiceUrl($response->data['invoiceUrl'] ?? null, $connection->environment);
            if ($url === null) throw new Conflict('Verified checkout payment link is unavailable.');
        }
        return ['tenantId' => $tenant, 'obligationId' => $obligation, 'environment' => $connection->environment,
            'state' => $state, 'providerStatus' => $verified['status'], 'invoiceUrl' => $url];
    }

    public static function invoiceUrl(mixed $url, string $environment): ?string
    {
        $host = match ($environment) { 'sandbox' => 'sandbox.asaas.com', 'production' => 'www.asaas.com', default => null };
        return $host !== null && is_string($url) && preg_match('#^https://' . preg_quote($host, '#') . '/i/[a-zA-Z0-9_-]{1,160}$#D', $url) ? $url : null;
    }
}
