<?php

namespace Espo\Modules\FeatureIntegrationMedx\Services;

use Espo\Core\Di;
use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * HTTP client for the MEDX REST API.
 *
 * Endpoints:
 *   - GetContatosGrid   (mass-import grid listing)
 *   - GetContatosFichaById (single-contact detail enrichment)
 */
class MedxApiClient implements
    Di\LogAware
{
    use Di\LogSetter;

    private const DEFAULT_BASE_URL = 'https://v65.medx.med.br';
    private const TIMEOUT_SECONDS = 30;
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const MAX_ATTEMPTS = 3;
    private const RETRY_BACKOFF_MS = 400;

    public function __construct(
        private CredentialResolver $credentialResolver,
        private EntityManager $entityManager,
    ) {}

    /**
     * Fetch the full client grid (for mass import).
     *
     * @param int $top  Maximum number of rows to return.
     * @param int $skip Number of rows to skip (for pagination).
     * @return array{total: int, rows: array<array<string, mixed>>}
     * @throws Error
     */
    public function getContatosGrid(Entity $credential, int $top = 10000, int $skip = 0): array
    {
        $bearerToken = $this->extractBearerToken($credential);
        $sessionCookies = $this->extractSessionCookies($credential);

        $query = http_build_query([
            'top'  => $top,
            'skip' => $skip,
        ], '', '&', PHP_QUERY_RFC3986);

        $url = self::DEFAULT_BASE_URL . '/api/Contatos/GetContatosGrid?' . $query;

        $result = $this->request($url, $bearerToken, $sessionCookies);

        if (!$result['ok']) {
            $status  = $result['status'];
            $message = $result['message'];

            throw new Error("MEDX GetContatosGrid request failed (HTTP {$status}): {$message}");
        }

        $payload = $result['payload'];

        // The MEDX GetContatosGrid endpoint returns a flat JSON array of
        // row objects. Each row carries a 'total' field with the overall
        // count.  There is no wrapper object like {total, rows}.
        if (isset($payload[0]) && is_array($payload[0])) {
            $rows  = $payload;
            $total = isset($rows[0]['total']) ? (int) $rows[0]['total'] : count($rows);
        } elseif (isset($payload['rows']) && is_array($payload['rows'])) {
            // Fallback in case the API format ever changes to a wrapper.
            $rows  = $payload['rows'];
            $total = isset($payload['total']) ? (int) $payload['total'] : count($rows);
        } else {
            $rows  = [];
            $total = 0;
        }

        return [
            'total' => $total,
            'rows'  => $rows,
        ];
    }

    /**
     * Fetch a single contact ficha (detail enrichment).
     *
     * @return array<string, mixed>
     * @throws Error
     */
    public function getClienteById(Entity $credential, string $clienteId): array
    {
        $bearerToken    = $this->extractBearerToken($credential);
        $sessionCookies = $this->extractSessionCookies($credential);

        $query = http_build_query(['Id' => $clienteId], '', '&', PHP_QUERY_RFC3986);
        $url   = self::DEFAULT_BASE_URL
            . '/api/contatos/GetContatosFichaById?' . $query;

        $result = $this->request($url, $bearerToken, $sessionCookies);

        if (!$result['ok']) {
            $status  = $result['status'];
            $message = $result['message'];

            throw new Error(
                "MEDX GetContatosFichaById request failed for '{$clienteId}' (HTTP {$status}): {$message}"
            );
        }

        $payload = $result['payload'];

        // The API wraps the record in an array.
        if (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
            return $this->mapClientePayload($payload[0]);
        }

        return $this->mapClientePayload($payload);
    }

    /**
     * Fetch a single contact ficha using the credential resolved from the
     * client itself.
     *
     * @return array<string, mixed>
     * @throws Error
     */
    public function getClienteByIdAuto(string $clienteId): array
    {
        $credential = $this->resolveActiveCredential();

        return $this->getClienteById($credential, $clienteId);
    }

    /* ──────────────────────────────────────────────────────────────── */
    /*  Internal helpers                                                 */
    /* ──────────────────────────────────────────────────────────────── */

    /**
     * Resolve a credential entity by type code 'medx-web'.
     * Used only by getClienteByIdAuto() as a fallback when no credential is
     * provided explicitly.
     */
    private function resolveActiveCredential(): Entity
    {
        $credentialType = $this->entityManager
            ->getRDBRepository('CredentialType')
            ->where(['code' => 'medx-web'])
            ->findOne();

        if (!$credentialType) {
            throw new Error('MEDX Web credential type not found.');
        }

        $credential = $this->entityManager
            ->getRDBRepository('Credential')
            ->where([
                'credentialTypeId' => $credentialType->getId(),
                'isActive' => true,
            ])
            ->findOne();

        if (!$credential) {
            throw new Error('No active MEDX Web credential found.');
        }

        return $credential;
    }

    /**
     * @return array{ok: bool, status: int, retryable: bool,
     *                payload: array<string, mixed>, message: string}
     */
    private function request(string $url, string $bearerToken, string $sessionCookies): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return [
                'ok'      => false,
                'status'  => 0,
                'retryable' => true,
                'payload' => [],
                'message' => 'Could not initialize cURL.',
            ];
        }

        $headers = [
            'Accept: application/json',
        ];

        if ($bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }

        if ($sessionCookies !== '') {
            $headers[] = 'Cookie: ' . $sessionCookies;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response  = curl_exec($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            return [
                'ok'        => false,
                'status'    => 0,
                'retryable' => true,
                'payload'   => [],
                'message'   => $curlError !== '' ? $curlError : 'Unknown transport error.',
            ];
        }

        $payload = json_decode((string) $response, true);

        if (!is_array($payload)) {
            $payload = [];
        }

        if ($status >= 200 && $status < 300) {
            return [
                'ok'        => true,
                'status'    => $status,
                'retryable' => false,
                'payload'   => $payload,
                'message'   => 'ok',
            ];
        }

        $message   = $this->extractApiErrorMessage($payload, (string) $response);
        $retryable = $status === 429 || $status >= 500 || $status === 0;

        return [
            'ok'        => false,
            'status'    => $status,
            'retryable' => $retryable,
            'payload'   => $payload,
            'message'   => $message,
        ];
    }

    /**
     * Map a single raw MEDX contact payload to normalised entity fields.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function mapClientePayload(array $raw): array
    {
        return [
            'name'                => $this->nullableString($raw['Nome'] ?? null),
            'assinaturaId'        => $this->nullableInt($raw['Id_da_Assinatura'] ?? null),
            'clienteId'           => $this->nullableString($raw['Id_do_Cliente'] ?? null),
            'cpfCgc'              => $this->nullableString($raw['CPF_CGC'] ?? null),
            'dataNascimento'      => $this->normalizeDate($raw['Nascimento'] ?? null),
            'emailAddress'        => $this->nullableString(
                $this->isNullOrString('null', $raw['Email'] ?? null)
            ),
            'celular'             => $this->nullableString($raw['Celular'] ?? null),
            'telefoneResidencial'  => $this->nullableString($raw['Telefone_Residencial'] ?? null),
            'telefoneResidencial1' => $this->nullableString($raw['Telefone_Residencial_1'] ?? null),
            'sexo'                => $this->nullableString($raw['Sexo'] ?? null),
            'nomeSocial'          => $this->nullableString($raw['Nome_Social'] ?? null),
            'estadoCivil'         => $this->nullableString($raw['Estado_Civil'] ?? null),
            'profissao'           => $this->nullableString($raw['Profissao'] ?? null),
            'enderecoResidencial'  => $this->nullableString($raw['Endereco_Residencial'] ?? null),
            'bairroResidencial'    => $this->nullableString($raw['Bairro_Residencial'] ?? null),
            'cidadeResidencial'    => $this->nullableString($raw['Cidade_Residencial'] ?? null),
            'estadoResidencial'    => $this->nullableString($raw['Estado_Residencial'] ?? null),
            'cepResidencial'       => $this->nullableString($raw['Cep_Residencial'] ?? null),
            'idDoConvenio'        => $this->nullableInt($raw['Id_do_Convenio'] ?? $raw['IddoConvenio'] ?? null),
            'convenio'            => $this->nullableString($raw['Convenio'] ?? null),
            'numeroMatricula'     => $this->nullableString($raw['Numero_da_Matricula'] ?? null),
            'numeroCns'           => $this->nullableString($raw['NumeroCNS'] ?? null),
            'observacoes'         => $this->nullableString($raw['Observacoes'] ?? null),
            'referencias'         => $this->nullableString($raw['Referencias'] ?? null),
            'tags'                => $this->nullableString($raw['Tags'] ?? null),
            'vip'                 => $this->nullableBool($raw['VIP'] ?? null),
            'malaDireta'          => $this->nullableBool($raw['Mala_Direta'] ?? null),
            'pendente'            => $this->normalizeBoolFromFauxBool(
                $raw['Pendente'] ?? null
            ),
            'excluiMkt'           => $this->nullableInt($raw['Exclui_Mkt'] ?? null),
            'indicadoPor'         => $this->nullableInt($raw['Indicado_por'] ?? null),
            'comoConheceu'        => $this->nullableString($raw['Como_conheceu'] ?? null),
            'escolaridade'        => $this->nullableString($raw['Escolaridade'] ?? null),
            'religiao'            => $this->nullableString($raw['Religiao'] ?? null),
        ];
    }

    private function extractBearerToken(Entity $credential): string
    {
        $config = $this->extractCredentialConfig($credential);

        return (string) ($config['bearerToken'] ?? '');
    }

    private function extractSessionCookies(Entity $credential): string
    {
        $config = $this->extractCredentialConfig($credential);

        return (string) ($config['sessionCookies'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractCredentialConfig(Entity $credential): array
    {
        $credentialId = $credential->getId();

        if (!is_string($credentialId) || $credentialId === '') {
            return [];
        }

        $data = $this->credentialResolver->resolve($credentialId);

        return (array) $data;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Strip trailing time portion if present (ISO 8601 with TZ).
        $pos = strpos($value, 'T');

        if ($pos !== false) {
            $value = substr($value, 0, $pos);
        }

        return $value !== '' ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        if (is_scalar($value)) {
            $trimmed = trim((string) $value);

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $intVal = (int) $value;

            return $intVal !== 0 ? $intVal : null;
        }

        return null;
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (bool) $value;
    }

    /**
     * The MEDX API uses the strings "true" / "false" for booleans and the
     * literal string "null" for nulls.
     */
    private function normalizeBoolFromFauxBool(mixed $value): ?bool
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $lower = strtolower(trim((string) $value));

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        return null;
    }

    /**
     * Treat a literal string 'null' (case-insensitive) as null.
     */
    private function isNullOrString(string $nullLiteral, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strtolower(trim($value)) === strtolower($nullLiteral)) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractApiErrorMessage(array $payload, string $raw): string
    {
        if (isset($payload['message']) && is_string($payload['message'])) {
            return $payload['message'];
        }

        if (isset($payload['error']) && is_string($payload['error'])) {
            return $payload['error'];
        }

        if (isset($payload['errors']) && is_array($payload['errors'])) {
            $messages = [];

            foreach ($payload['errors'] as $error) {
                if (is_string($error)) {
                    $messages[] = $error;
                    continue;
                }

                if (isset($error['message']) && is_string($error['message'])) {
                    $messages[] = $error['message'];
                }
            }

            if ($messages !== []) {
                return implode('; ', $messages);
            }
        }

        $preview = substr($raw, 0, 300);

        if ($preview !== '') {
            return $preview;
        }

        return 'Unknown server error.';
    }
}
