<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

use Espo\Modules\Global\Tools\BrazilianIdentifier;

final class Hub extends Supplier
{
    protected function provider(): string { return 'hub'; }
    protected function eligible(string $type, string $product, array $identity): bool
    {
        return in_array($product, ['email', 'phone'], true) && match ($type) {
            'Contact' => BrazilianIdentifier::cpf($identity['cpf'] ?? null) !== null,
            // Alphanumeric Hub support is not yet documented; do not silently strip letters.
            'Account' => ($cnpj = BrazilianIdentifier::cnpj($identity['cnpj'] ?? null)) !== null && ctype_digit($cnpj),
            default => false,
        };
    }

    public function lookup(string $type, string $product, array $identity, int $timeoutMs = 30000): Result
    {
        $connection = $this->connection($type, $product, $identity);
        if (!$connection) return new Result('failed');
        $person = $type === 'Contact';
        $document = $person ? BrazilianIdentifier::cpf($identity['cpf']) : BrazilianIdentifier::cnpj($identity['cnpj']);
        $response = $this->transport->send('hub', 'GET', $person ? '/v2/cadastropf/' : '/v2/cnpj/',
            [...$connection, $person ? 'cpf' : 'cnpj' => $document], timeoutMs: $timeoutMs);
        if ($failure = $this->httpFailure($response)) return $failure;
        $body = $response->data;
        if (in_array($body['status'] ?? null, [false, 'false'], true) && ($body['return'] ?? null) === 'NOK') return new Result('failed');
        $data = $body['result'] ?? null;
        if (!in_array($body['status'] ?? null, [true, 'true'], true) || ($body['return'] ?? null) !== 'OK' || !is_array($data)) return new Result('ambiguous');
        $returned = $person ? BrazilianIdentifier::cpf($data['documento'] ?? null) : BrazilianIdentifier::cnpj($data['numero_de_inscricao'] ?? null);
        if ($returned !== $document) return new Result('ambiguous');
        if (!$person) return $this->result([$data['email'] ?? null], [$data['telefone'] ?? null], $product, true);
        if (!is_array($data['listaEmails'] ?? []) || !is_array($data['listaTelefones'] ?? [])) return new Result('ambiguous');
        $emails = array_map(static fn ($item) => is_array($item) ? ($item['enderecoEmail'] ?? null) : null, $data['listaEmails'] ?? []);
        $phones = array_map(static fn ($item) => is_array($item) ? ($item['telefoneComDDD'] ?? null) : null, $data['listaTelefones'] ?? []);
        return $this->result($emails, $phones, $product, true, self::dateOfBirth($data));
    }

    private static function dateOfBirth(array $data): ?string
    {
        $raw = $data['dataDeNascimento'] ?? $data['dataNascimento'] ?? $data['data_nascimento'] ?? null;
        if (!is_string($raw)) return null;
        $value = trim($raw);
        if (preg_match('/\A(\d{2})\/(\d{2})\/(\d{4})\z/', $value, $m)) {
            $value = "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m)) return null;
        $year = (int) $m[1];
        if ($year < 1900 || !checkdate((int) $m[2], (int) $m[3], $year)) return null;
        return $value;
    }
}
