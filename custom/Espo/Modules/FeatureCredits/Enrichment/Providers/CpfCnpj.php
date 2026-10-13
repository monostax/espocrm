<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

use Espo\Modules\Global\Tools\BrazilianIdentifier;

final class CpfCnpj extends Supplier
{
    protected function provider(): string { return 'cpfcnpj'; }
    protected function eligible(string $type, string $product, array $identity): bool
    {
        return in_array($product, ['email', 'phone'], true) && match ($type) {
            'Contact' => BrazilianIdentifier::cpf($identity['cpf'] ?? null) !== null,
            'Account' => BrazilianIdentifier::cnpj($identity['cnpj'] ?? null) !== null,
            default => false,
        };
    }

    public function lookup(string $type, string $product, array $identity, int $timeoutMs = 30000): Result
    {
        $connection = $this->connection($type, $product, $identity);
        if (!$connection) return new Result('failed');
        $package = $type === 'Contact' ? 21 : 6;
        $document = $type === 'Contact' ? BrazilianIdentifier::cpf($identity['cpf']) : BrazilianIdentifier::cnpj($identity['cnpj']);
        $response = $this->transport->send('cpfcnpj', 'GET', '/' . rawurlencode($connection['token']) . '/' . $package . '/' . $document,
            timeoutMs: $timeoutMs);
        if ($failure = $this->httpFailure($response)) return $failure;
        $data = $response->data;
        if (($data['status'] ?? null) === 0) {
            return new Result(in_array($data['erroCodigo'] ?? null, [102, 202], true) ? 'no_match' : 'failed');
        }
        $returned = $type === 'Contact' ? BrazilianIdentifier::cpf($data['cpf'] ?? null) : BrazilianIdentifier::cnpj($data['cnpj'] ?? null);
        if (($data['status'] ?? null) !== 1 || ($data['pacoteUsado'] ?? null) !== $package || $returned !== $document) return new Result('ambiguous');
        $emails = $type === 'Contact' ? ($data['emails'] ?? []) : [($data['email'] ?? null)];
        $phones = $data['telefones'] ?? [];
        if (!is_array($emails) || !is_array($phones)) return new Result('ambiguous');
        if ($type === 'Account') {
            $phones = array_map(static fn ($phone) => is_array($phone) && is_string($phone['ddd'] ?? null) && is_string($phone['numero'] ?? null) ?
                $phone['ddd'] . $phone['numero'] : null, $phones);
        }
        // Never read related socios/responsavel contacts as the Account's own values.
        return $this->result($emails, $phones, $product, true);
    }
}
