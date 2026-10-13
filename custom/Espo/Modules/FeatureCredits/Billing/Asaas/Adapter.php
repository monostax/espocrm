<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing\Asaas;

use Brick\Math\BigDecimal;

/** Creation/recovery only. A bound resource is not verified payment and never authorizes credits. */
final class Adapter
{
    public function __construct(private Transport $transport) {}

    public function create(Connection $connection, Command $command): array
    {
        if (!$connection->creationEnabled) throw new \LogicException('Asaas creation is disabled.');
        $response = $this->transport->send($connection, 'POST', $command->path(), $command->json());
        return $this->match($command, $response) ?? ['state' => 'unknown', 'reason' => 'creation_unverified'];
    }

    /** One bounded exact-reference search; no match/partial/multiple matches never permit a new POST. */
    public function reconcile(Connection $connection, Command $command): array
    {
        $query = ['externalReference' => $command->body['externalReference'], 'limit' => 2, 'offset' => 0];
        if ($command->kind === 'subscription') $query['includeDeleted'] = 'true';
        $response = $this->transport->send($connection, 'GET', $command->path(), query: $query);
        $data = $response->data;
        if ($response->status !== 200 || !is_array($data) || ($data['hasMore'] ?? null) !== false ||
            ($data['totalCount'] ?? null) !== '1' || ($data['offset'] ?? null) !== '0' ||
            !is_array($data['data'] ?? null) || !array_is_list($data['data']) || count($data['data']) !== 1 || !is_array($data['data'][0])) {
            return ['state' => 'unknown', 'reason' => 'lookup_unresolved'];
        }
        return $this->match($command, new Response(200, $data['data'][0])) ?? ['state' => 'unknown', 'reason' => 'lookup_mismatch'];
    }

    private function match(Command $command, Response $response): ?array
    {
        $data = $response->data; $body = $command->body;
        if (!in_array($response->status, [200, 201], true) || !is_array($data) || !is_string($data['id'] ?? null) ||
            ($data['externalReference'] ?? null) !== $body['externalReference'] || ($data['deleted'] ?? null) !== false) return null;
        try { Command::externalId($command->kind, $data['id']); } catch (\InvalidArgumentException) { return null; }
        if ($command->kind === 'customer') {
            if (($data['cpfCnpj'] ?? null) !== $body['cpfCnpj'] || ($data['name'] ?? null) !== $body['name'] ||
                ($data['notificationDisabled'] ?? null) !== $body['notificationDisabled'] ||
                (isset($body['email']) && (!is_string($data['email'] ?? null) || strtolower($data['email']) !== $body['email']))) return null;
        } else {
            if (($data['customer'] ?? null) !== $body['customer'] || !is_string($data['value'] ?? null) ||
                !preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $data['value'])) return null;
            if (BigDecimal::of($data['value'])->compareTo(BigDecimal::of($body['value'])) !== 0) return null;
            $types = $body['billingType'] === 'UNDEFINED' ? ['UNDEFINED', 'PIX', 'BOLETO', 'CREDIT_CARD'] : [$body['billingType']];
            if (!in_array($data['billingType'] ?? null, $types, true)) return null;
            if ($command->kind === 'payment' && (($data['originalDueDate'] ?? $data['dueDate'] ?? null) !== $body['dueDate'] ||
                !empty($data['subscription']) || !empty($data['installment']))) return null;
            // nextDueDate is mutable as charges are generated. Never reconstruct original periods from it.
            if ($command->kind === 'subscription' && ($data['cycle'] ?? null) !== 'MONTHLY') return null;
        }
        return ['state' => 'bound', 'externalId' => $data['id']];
    }
}
