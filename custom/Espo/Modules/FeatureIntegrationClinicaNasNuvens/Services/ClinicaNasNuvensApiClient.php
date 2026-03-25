<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Di;
use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\ORM\Entity;

class ClinicaNasNuvensApiClient implements
    Di\LogAware
{
    use Di\LogSetter;

    private const DEFAULT_BASE_URL = 'https://api.clinicanasnuvens.com.br';
    private const TIMEOUT_SECONDS = 20;
    private const CONNECT_TIMEOUT_SECONDS = 8;
    private const MAX_ATTEMPTS = 3;
    private const RETRY_BACKOFF_MS = 400;

    /**
     * @var array<int, array{cidade: ?string, estado: ?string}>
     */
    private array $cidadeCache = [];

    public function __construct(
        private CredentialResolver $credentialResolver,
    ) {}

    /**
     * Get a single paciente profile from CNN API.
     *
     * @return array<string, mixed>
     * @throws Error
     */
    public function getPacienteById(Entity $credential, string $pacienteId): array
    {
        $config = $this->extractCredentialConfig($credential);

        $baseUrl = rtrim((string) ($config['baseUrl'] ?? self::DEFAULT_BASE_URL), '/');
        $clientId = (string) ($config['clientId'] ?? $config['client_id'] ?? '');
        $clientSecret = (string) ($config['clientSecret'] ?? $config['client_secret'] ?? '');
        $clinicCid = (string) ($config['clinicCid'] ?? $config['clinicCID'] ?? $config['cid'] ?? $config['clinicToken'] ?? '');

        if ($clientId === '' || $clientSecret === '' || $clinicCid === '') {
            throw new Error('Credential config must contain clientId, clientSecret and clinicCid.');
        }

        $url = $baseUrl . '/paciente/' . rawurlencode($pacienteId);

        $attempt = 0;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;

            $result = $this->request($url, $clientId, $clientSecret, $clinicCid);

            if ($result['ok']) {
                return $this->mapPacientePayload(
                    $result['payload'],
                    $baseUrl,
                    $clientId,
                    $clientSecret,
                    $clinicCid,
                );
            }

            $retryable = $result['retryable'];

            if (!$retryable || $attempt >= self::MAX_ATTEMPTS) {
                $status = $result['status'];
                $message = $result['message'];

                throw new Error(
                    "Clínica nas Nuvens request failed for paciente '{$pacienteId}' (HTTP {$status}): {$message}"
                );
            }

            usleep(self::RETRY_BACKOFF_MS * $attempt * 1000);
        }

        throw new Error("Clínica nas Nuvens request failed for paciente '{$pacienteId}'.");
    }

    /**
     * @return array{ok: bool, status: int, retryable: bool, payload: array<string, mixed>, message: string}
     */
    private function request(string $url, string $clientId, string $clientSecret, string $clinicCid): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return [
                'ok' => false,
                'status' => 0,
                'retryable' => true,
                'payload' => [],
                'message' => 'Could not initialize cURL.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $clientId . ':' . $clientSecret,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'clinicaNasNuvens-cid: ' . $clinicCid,
            ],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            return [
                'ok' => false,
                'status' => 0,
                'retryable' => true,
                'payload' => [],
                'message' => $curlError !== '' ? $curlError : 'Unknown transport error.',
            ];
        }

        $payload = json_decode((string) $response, true);

        if (!is_array($payload)) {
            $payload = [];
        }

        if ($status >= 200 && $status < 300) {
            return [
                'ok' => true,
                'status' => $status,
                'retryable' => false,
                'payload' => $payload,
                'message' => 'ok',
            ];
        }

        $message = $this->extractApiErrorMessage($payload, (string) $response);
        $retryable = $status === 429 || $status >= 500 || $status === 0;

        return [
            'ok' => false,
            'status' => $status,
            'retryable' => $retryable,
            'payload' => $payload,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPacientePayload(
        array $payload,
        string $baseUrl,
        string $clientId,
        string $clientSecret,
        string $clinicCid,
    ): array
    {
        $source = $payload;

        if (isset($payload['data']) && is_array($payload['data'])) {
            $source = $payload['data'];
        }

        $contato = isset($source['contato']) && is_array($source['contato']) ? $source['contato'] : [];
        $endereco = isset($source['endereco']) && is_array($source['endereco']) ? $source['endereco'] : [];
        $idCidade = isset($endereco['idCidade']) ? (int) $endereco['idCidade'] : null;

        $cidade = $source['cidade'] ?? $endereco['cidade'] ?? $endereco['nomeCidade'] ?? null;
        if (is_array($cidade)) {
            $cidade = $cidade['nome'] ?? $cidade['descricao'] ?? $cidade['cidade'] ?? null;
        }

        $estado = $source['estado'] ?? $endereco['estado'] ?? $endereco['uf'] ?? $endereco['siglaEstado'] ?? null;
        if (is_array($estado)) {
            $estado = $estado['sigla'] ?? $estado['uf'] ?? $estado['nome'] ?? null;
        }

        if (($cidade === null || $estado === null) && $idCidade) {
            $cidadeEstado = $this->fetchCidadeEstadoById(
                $baseUrl,
                $clientId,
                $clientSecret,
                $clinicCid,
                $idCidade,
            );

            if ($cidade === null) {
                $cidade = $cidadeEstado['cidade'];
            }

            if ($estado === null) {
                $estado = $cidadeEstado['estado'];
            }
        }

        return [
            'name' => $source['nome'] ?? $source['name'] ?? null,
            'ativo' => $source['ativo'] ?? null,
            'cpfcnpj' => $source['cpfcnpj'] ?? null,
            'dataNascimento' => $source['dataNascimento'] ?? null,
            'email' => $source['email'] ?? $contato['email'] ?? null,
            'telefone' => $source['telefone'] ?? $contato['telefoneComercial'] ?? $contato['telefoneResidencial'] ?? null,
            'celular' => $source['celular'] ?? $contato['telefoneCelular'] ?? null,
            'sexo' => $source['sexo'] ?? null,
            'nomeMae' => $source['nomeMae'] ?? null,
            'nomePai' => $source['nomePai'] ?? null,
            'estadoCivil' => $source['estadoCivil'] ?? null,
            'profissao' => $source['profissao'] ?? null,
            'endereco' => is_string($source['endereco'] ?? null) ? $source['endereco'] : ($endereco['rua'] ?? null),
            'numero' => $source['numero'] ?? $endereco['numero'] ?? null,
            'complemento' => $source['complemento'] ?? $endereco['complemento'] ?? null,
            'bairro' => $source['bairro'] ?? $endereco['bairro'] ?? null,
            'cidade' => $cidade,
            'estado' => $estado,
            'cep' => $source['cep'] ?? $endereco['cep'] ?? null,
            'observacao' => $source['observacao'] ?? null,
            'convenio' => $source['convenio'] ?? null,
            'numeroConvenio' => $source['numeroConvenio'] ?? null,
            'validadeConvenio' => $source['validadeConvenio'] ?? null,
        ];
    }

    /**
     * @return array{cidade: ?string, estado: ?string}
     */
    private function fetchCidadeEstadoById(
        string $baseUrl,
        string $clientId,
        string $clientSecret,
        string $clinicCid,
        int $idCidade,
    ): array {
        if (isset($this->cidadeCache[$idCidade])) {
            return $this->cidadeCache[$idCidade];
        }

        $url = $baseUrl . '/cidade/' . $idCidade;

        $result = $this->request($url, $clientId, $clientSecret, $clinicCid);

        if (!$result['ok']) {
            $this->cidadeCache[$idCidade] = ['cidade' => null, 'estado' => null];

            return $this->cidadeCache[$idCidade];
        }

        $payload = $result['payload'];
        $estadoData = isset($payload['estado']) && is_array($payload['estado']) ? $payload['estado'] : [];

        $cidade = $payload['nome'] ?? null;
        $estado = $estadoData['sigla'] ?? $estadoData['nome'] ?? null;

        $this->cidadeCache[$idCidade] = [
            'cidade' => is_string($cidade) ? $cidade : null,
            'estado' => is_string($estado) ? $estado : null,
        ];

        return $this->cidadeCache[$idCidade];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractCredentialConfig(Entity $credential): array
    {
        $credentialId = $credential->getId();

        if ($credentialId) {
            try {
                $resolved = $this->credentialResolver->resolve($credentialId);

                return (array) $resolved;
            } catch (\Throwable $e) {
                $this->log->warning(
                    "ClinicaNasNuvensApiClient: failed to resolve credential '{$credentialId}', fallback to raw config. " .
                    $e->getMessage()
                );
            }
        }

        $config = $credential->get('config');

        if (is_string($config) && $config !== '') {
            $decoded = json_decode($config, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (is_array($config)) {
            return $config;
        }

        if ($config instanceof \stdClass) {
            return (array) $config;
        }

        return [];
    }

    private function extractApiErrorMessage(array $payload, string $fallback): string
    {
        if (!empty($payload['message']) && is_string($payload['message'])) {
            return $payload['message'];
        }

        if (!empty($payload['error']) && is_string($payload['error'])) {
            return $payload['error'];
        }

        $trimmed = trim($fallback);

        if ($trimmed !== '') {
            return $trimmed;
        }

        return 'Unknown API error.';
    }
}
