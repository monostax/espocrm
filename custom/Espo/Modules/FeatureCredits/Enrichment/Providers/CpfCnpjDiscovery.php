<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

use Espo\Modules\FeatureCredits\Enrichment\{CandidateProjection, DiscoverySupplier, DiscoveryResult, Quotes};
use Espo\Modules\Global\Tools\BrazilianIdentifier;

/** Package 6 QSA only; no package 19/25 or representative/contact expansion. */
final class CpfCnpjDiscovery implements DiscoverySupplier
{
    public function __construct(private Credentials $credentials, private Transport $transport) {}
    public function retentionSeconds(string $product): int { return 90 * 86400; }

    public function revision(string $tenantId, string $targetType, string $product, array $identity): ?string
    {
        $connection = $this->credentials->get('cpfcnpj');
        if (!$connection || $targetType !== 'Account' || $product !== 'socios' || BrazilianIdentifier::cnpj($identity['cnpj'] ?? null) === null) return null;
        return Quotes::hash(['cpfcnpj-package6-qsa-v1', 'retention-reuse-approved-max90d', $tenantId, $connection, $this->retentionSeconds($product)]);
    }

    public function execute(array $request, int $timeoutMs): DiscoveryResult
    {
        $cnpj = BrazilianIdentifier::cnpj($request['identity']['cnpj'] ?? null);
        $connection = $this->credentials->get('cpfcnpj');
        if (!$connection || $cnpj === null || ($request['product'] ?? null) !== 'socios' || ($request['kind'] ?? null) !== 'page' ||
            ($request['input']['cursor'] ?? null) !== null) return new DiscoveryResult('failed');
        $response = $this->transport->send('cpfcnpj', 'GET', '/' . rawurlencode($connection['token']) . '/6/' . $cnpj, timeoutMs: $timeoutMs);
        if (in_array($response->status, [400, 401, 403, 422, 429], true)) return new DiscoveryResult('failed');
        $data = $response->data;
        if ($response->status !== 200 || $data === null) return new DiscoveryResult('unknown');
        if (($data['status'] ?? null) === 0) return new DiscoveryResult(in_array($data['erroCodigo'] ?? null, [102, 202], true) ? 'no_match' : 'failed');
        if (($data['status'] ?? null) !== 1 || ($data['pacoteUsado'] ?? null) !== 6 ||
            BrazilianIdentifier::cnpj($data['cnpj'] ?? null) !== $cnpj || !is_array($data['socios'] ?? null) || !array_is_list($data['socios'])) return new DiscoveryResult('unknown');
        $items = [];
        foreach ($data['socios'] as $person) {
            if (!is_array($person) || ($person['tipo'] ?? null) !== 'Pessoa Física') continue;
            $cpf = BrazilianIdentifier::cpf($person['cpf_cnpj_socio'] ?? null);
            if ($cpf === null || !is_string($person['nome'] ?? null) || trim($person['nome']) === '') continue;
            $values = ['cpf' => $cpf, 'name' => $person['nome']];
            if (is_string($person['qualificacao_socio']['descricao'] ?? null) && trim($person['qualificacao_socio']['descricao']) !== '') $values['role'] = $person['qualificacao_socio']['descricao'];
            if (is_string($person['data_entrada'] ?? null) && $person['data_entrada'] !== '') $values['since'] = $person['data_entrada'];
            try { $values = CandidateProjection::normalize('socios', $values); }
            catch (\InvalidArgumentException) { continue; }
            $items[] = ['candidateKey' => Quotes::hash(['cpfcnpj', $cpf]), 'values' => $values, 'naturalPerson' => true];
        }
        return new DiscoveryResult($items ? 'succeeded' : 'no_match', $items);
    }
}
