<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use DOMDocument;
use DOMXPath;
use Espo\Core\Di;
use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckManager;
use Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckers\HealthCheckResult;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ClinicaNasNuvensWebClient implements
    Di\LogAware
{
    use Di\LogSetter;

    private const DETAILS_URL_TEMPLATE =
        'https://app.clinicanasnuvens.com.br/lancamentos/detalhesConta?codigoDetalhe=%s';
    private const RESUMO_AGENDA_FINANCEIRO_URL_TEMPLATE =
        'https://app.clinicanasnuvens.com.br/resumoAgenda-financeiro?codigoAgenda=%s';
    private const PROCEDIMENTO_URL_TEMPLATE =
        'https://app.clinicanasnuvens.com.br/procedimento/%s?page=1&ativo=';
    private const EXPORTACAO_LISTA_URL =
        'https://app.clinicanasnuvens.com.br/exportacao/lista';
    private const EXPORTACAO_SALVAR_URL =
        'https://app.clinicanasnuvens.com.br/exportacao/salvar';
    private const EXPORTACAO_DOWNLOAD_URL_TEMPLATE =
        'https://app.clinicanasnuvens.com.br/exportacao/download/?codArquivo=%s';
    private const TIMEOUT_SECONDS = 20;
    private const DOWNLOAD_TIMEOUT_SECONDS = 60;
    private const CONNECT_TIMEOUT_SECONDS = 8;
    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
        '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const XPATH_DOCUMENTO =
        '/html/body/div[2]/div[contains(@class,"card-body") and contains(@class,"pt-0") and contains(@class,"pb-0")]/div/div[1]/div/div[1]/div[contains(@class,"col") and contains(@class,"text-gray-900") and contains(@class,"abrevia-texto")]/span';
    private const XPATH_DATA_FATURAMENTO =
        '/html/body/div[2]/div[contains(@class,"card-body") and contains(@class,"pt-0") and contains(@class,"pb-0")]/div/div[1]/div/div[3]/div[contains(@class,"col") and contains(@class,"text-gray-900") and contains(@class,"abrevia-texto") and contains(@class,"fw-medium")]/span';
    private const XPATH_PROFISSIONAL_NOME =
        '/html/body/div[2]/div[contains(@class,"card-body") and contains(@class,"pt-0") and contains(@class,"pb-0")]/div/div[1]/div/div[5]/div[contains(@class,"col") and contains(@class,"text-gray-900") and contains(@class,"abrevia-texto") and contains(@class,"fw-medium")]/span';
    private const XPATH_CONTA =
        '/html/body/div[2]/div[contains(@class,"card-body") and contains(@class,"pt-0") and contains(@class,"pb-0")]/div/div[1]/div/div[7]/div[contains(@class,"col") and contains(@class,"text-gray-900") and contains(@class,"abrevia-texto") and contains(@class,"fw-medium")]/span';
    private const XPATH_VALOR =
        '/html/body/div[2]/div[contains(@class,"card-body") and contains(@class,"pt-0") and contains(@class,"pb-0")]/div/div[2]/div/div[1]/div[contains(@class,"col") and contains(@class,"text-gray-900") and contains(@class,"abrevia-texto") and contains(@class,"fw-medium")]/span';
    private const XPATH_PARCELA =
        '/html/body/div[2]/div[contains(@class,"card-body") and contains(@class,"pt-0") and contains(@class,"pb-0")]/div/div[2]/div/div[3]/div[contains(@class,"col") and contains(@class,"text-gray-900") and contains(@class,"abrevia-texto") and contains(@class,"fw-medium")]/span';
    private const XPATH_DATA_VENCIMENTO =
        '/html/body/div[2]/div[contains(@class,"card-body") and contains(@class,"pt-0") and contains(@class,"pb-0")]/div/div[2]/div/div[5]/div[contains(@class,"col") and contains(@class,"text-gray-900") and contains(@class,"abrevia-texto") and contains(@class,"fw-medium")]/span';
    private const XPATH_DESCRIPTION =
        '/html/body/div[2]/div[contains(@class,"col") and contains(@class,"text-gray-900") and contains(@class,"fw-medium") and contains(@class,"px-9") and contains(@class,"mb-4")]';
    private const XPATH_AGENDAMENTO_ID =
        '/html/body/div[1]/div[contains(@class,"d-flex") and contains(@class,"justify-content-between") and contains(@class,"py-5") and contains(@class,"px-9")]/div/b';
    private const XPATH_RESUMO_AGENDA_FINANCEIRO_ALERT_H1 =
        '//*[@id="tab_financeiro"]/div[1]/div/table/tbody/tr/td/div/div/h1';

    private const STATUS_FATURAMENTO_CANCELADO = 'CANCELADO';
    private const STATUS_FATURAMENTO_SEM_REGISTRO = 'SEM_REGISTRO';
    private const STATUS_FATURAMENTO_FATURADO = 'FATURADO';

    public function __construct(
        private CredentialResolver $credentialResolver,
        private HealthCheckManager $healthCheckManager,
        private EntityManager $entityManager,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getDetalhesConta(Entity $credential, string $faturamentoId): array
    {
        $url = sprintf(self::DETAILS_URL_TEMPLATE, rawurlencode($faturamentoId));
        $config = $this->extractCredentialConfig($credential);
        $cookies = $this->normalizeNullableString($config['sessionCookies'] ?? null);

        if (!$cookies) {
            throw new Error("Missing session cookies for credential '{$credential->getId()}'.");
        }

        $firstAttempt = $this->requestDetailsPage($url, $cookies);
        $firstValidation = $this->validateTargetHtml($firstAttempt['status'], $firstAttempt['body']);

        if ($firstValidation['valid']) {
            return $this->parseDetalhesContaHtml($firstAttempt['body'], $faturamentoId);
        }

        if (!$firstValidation['authLike']) {
            throw new Error(
                "Invalid faturamento HTML response for '{$faturamentoId}': {$firstValidation['reason']}"
            );
        }

        $refreshedCookies = $this->refreshCredentialSessionCookies($credential);

        if (!$refreshedCookies) {
            throw new Error(
                "Web session refresh failed for credential '{$credential->getId()}' while loading faturamento '{$faturamentoId}'."
            );
        }

        $retryAttempt = $this->requestDetailsPage($url, $refreshedCookies);
        $retryValidation = $this->validateTargetHtml($retryAttempt['status'], $retryAttempt['body']);

        if (!$retryValidation['valid']) {
            throw new Error(
                "Invalid faturamento HTML response after retry for '{$faturamentoId}': {$retryValidation['reason']}"
            );
        }

        return $this->parseDetalhesContaHtml($retryAttempt['body'], $faturamentoId);
    }

    /**
     * @return string[]
     */
    public function getFaturamentoIdsByAgendamentoId(Entity $credential, string $agendamentoId): array
    {
        $snapshot = $this->getResumoAgendaFinanceiroSnapshotByAgendamentoId($credential, $agendamentoId);

        return $snapshot['faturamentoIdList'];
    }

    public function getStatusFaturamentoByAgendamentoId(Entity $credential, string $agendamentoId): ?string
    {
        $snapshot = $this->getResumoAgendaFinanceiroSnapshotByAgendamentoId($credential, $agendamentoId);

        return $snapshot['statusFaturamento'];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, telemetry: array<string, int>}
     */
    public function getProcedimentoConvenioPricingByProcedimentoId(Entity $credential, string $procedimentoTipoId): array
    {
        $url = sprintf(self::PROCEDIMENTO_URL_TEMPLATE, rawurlencode($procedimentoTipoId));
        $config = $this->extractCredentialConfig($credential);
        $cookies = $this->normalizeNullableString($config['sessionCookies'] ?? null);

        if (!$cookies) {
            throw new Error("Missing session cookies for credential '{$credential->getId()}'.");
        }

        $firstAttempt = $this->requestDetailsPage($url, $cookies);
        $firstValidation = $this->validateProcedimentoHtml($firstAttempt['status'], $firstAttempt['body']);

        if ($firstValidation['valid']) {
            $rows = $this->parseProcedimentoConvenioPricingHtml($firstAttempt['body']);

            return [
                'rows' => $rows,
                'telemetry' => [
                    'attempts' => 1,
                    'rowsParsed' => count($rows),
                ],
            ];
        }

        if (!$firstValidation['authLike']) {
            throw new Error(
                "Invalid procedimento HTML response for '{$procedimentoTipoId}': {$firstValidation['reason']}"
            );
        }

        $refreshedCookies = $this->refreshCredentialSessionCookies($credential);

        if (!$refreshedCookies) {
            throw new Error(
                "Web session refresh failed for credential '{$credential->getId()}' while loading procedimento '{$procedimentoTipoId}'."
            );
        }

        $retryAttempt = $this->requestDetailsPage($url, $refreshedCookies);
        $retryValidation = $this->validateProcedimentoHtml($retryAttempt['status'], $retryAttempt['body']);

        if (!$retryValidation['valid']) {
            throw new Error(
                "Invalid procedimento HTML response after retry for '{$procedimentoTipoId}': {$retryValidation['reason']}"
            );
        }

        $rows = $this->parseProcedimentoConvenioPricingHtml($retryAttempt['body']);

        return [
            'rows' => $rows,
            'telemetry' => [
                'attempts' => 2,
                'rowsParsed' => count($rows),
            ],
        ];
    }

    /**
     * Get the file list from the most recent export row on the CNN exportação lista page.
     *
     * @return array<int, array{codigo: int, dataHoraCriacao: string}>
     */
    public function getExportFileList(Entity $credential): array
    {
        $config = $this->extractCredentialConfig($credential);
        $cookies = $this->normalizeNullableString($config['sessionCookies'] ?? null);

        if (!$cookies) {
            throw new Error("Missing session cookies for credential '{$credential->getId()}'.");
        }

        $firstAttempt = $this->requestDetailsPage(self::EXPORTACAO_LISTA_URL, $cookies);
        $firstValidation = $this->validateExportacaoListaHtml($firstAttempt['status'], $firstAttempt['body']);

        if ($firstValidation['valid']) {
            return $this->parseExportacaoListaHtml($firstAttempt['body']);
        }

        if (!$firstValidation['authLike']) {
            throw new Error(
                "Invalid exportação lista HTML response: {$firstValidation['reason']}"
            );
        }

        $refreshedCookies = $this->refreshCredentialSessionCookies($credential);

        if (!$refreshedCookies) {
            throw new Error(
                "Web session refresh failed for credential '{$credential->getId()}' while loading exportação lista."
            );
        }

        $retryAttempt = $this->requestDetailsPage(self::EXPORTACAO_LISTA_URL, $refreshedCookies);
        $retryValidation = $this->validateExportacaoListaHtml($retryAttempt['status'], $retryAttempt['body']);

        if (!$retryValidation['valid']) {
            throw new Error(
                "Invalid exportação lista HTML response after retry: {$retryValidation['reason']}"
            );
        }

        return $this->parseExportacaoListaHtml($retryAttempt['body']);
    }

    /**
     * POST to CNN exportação/salvar to request a new data export.
     *
     * @return array{success: bool, status: int, message: string}
     */
    public function requestNewExport(Entity $credential): array
    {
        $config = $this->extractCredentialConfig($credential);
        $cookies = $this->normalizeNullableString($config['sessionCookies'] ?? null);

        if (!$cookies) {
            throw new Error("Missing session cookies for credential '{$credential->getId()}'.");
        }

        $firstAttempt = $this->requestPostPage(self::EXPORTACAO_SALVAR_URL, $cookies);
        $firstValidation = $this->validateExportacaoSalvarResponse($firstAttempt['status'], $firstAttempt['body']);

        if ($firstValidation['valid']) {
            return ['success' => true, 'status' => $firstAttempt['status'], 'message' => 'ok'];
        }

        if (!$firstValidation['authLike']) {
            return ['success' => false, 'status' => $firstAttempt['status'], 'message' => $firstValidation['reason']];
        }

        $refreshedCookies = $this->refreshCredentialSessionCookies($credential);

        if (!$refreshedCookies) {
            return ['success' => false, 'status' => 0, 'message' => 'Session refresh failed.'];
        }

        $retryAttempt = $this->requestPostPage(self::EXPORTACAO_SALVAR_URL, $refreshedCookies);
        $retryValidation = $this->validateExportacaoSalvarResponse($retryAttempt['status'], $retryAttempt['body']);

        if (!$retryValidation['valid']) {
            return ['success' => false, 'status' => $retryAttempt['status'], 'message' => $retryValidation['reason']];
        }

        return ['success' => true, 'status' => $retryAttempt['status'], 'message' => 'ok'];
    }

    /**
     * Download a single CNN export zip file to the given destination path.
     *
     * Uses CURLOPT_FILE to stream directly to disk to avoid memory issues with large files.
     */
    public function downloadExportFile(Entity $credential, int $codArquivo, string $destPath): void
    {
        $config = $this->extractCredentialConfig($credential);
        $cookies = $this->normalizeNullableString($config['sessionCookies'] ?? null);

        if (!$cookies) {
            throw new Error("Missing session cookies for credential '{$credential->getId()}'.");
        }

        $url = sprintf(self::EXPORTACAO_DOWNLOAD_URL_TEMPLATE, $codArquivo);

        $firstResult = $this->requestDownloadToFile($url, $cookies, $destPath);

        if ($firstResult['valid']) {
            return;
        }

        if (!$firstResult['authLike']) {
            throw new Error(
                "Download failed for codArquivo '{$codArquivo}': {$firstResult['reason']}"
            );
        }

        // Clean up corrupted file from first attempt.
        if (file_exists($destPath)) {
            @unlink($destPath);
        }

        $refreshedCookies = $this->refreshCredentialSessionCookies($credential);

        if (!$refreshedCookies) {
            throw new Error(
                "Web session refresh failed for credential '{$credential->getId()}' while downloading codArquivo '{$codArquivo}'."
            );
        }

        $retryResult = $this->requestDownloadToFile($url, $refreshedCookies, $destPath);

        if (!$retryResult['valid']) {
            if (file_exists($destPath)) {
                @unlink($destPath);
            }

            throw new Error(
                "Download failed after retry for codArquivo '{$codArquivo}': {$retryResult['reason']}"
            );
        }
    }

    /**
     * @return array{faturamentoIdList: string[], statusFaturamento: ?string}
     */
    protected function getResumoAgendaFinanceiroSnapshotByAgendamentoId(Entity $credential, string $agendamentoId): array
    {
        $url = sprintf(self::RESUMO_AGENDA_FINANCEIRO_URL_TEMPLATE, rawurlencode($agendamentoId));
        $config = $this->extractCredentialConfig($credential);
        $cookies = $this->normalizeNullableString($config['sessionCookies'] ?? null);

        if (!$cookies) {
            throw new Error("Missing session cookies for credential '{$credential->getId()}'.");
        }

        $firstAttempt = $this->requestDetailsPage($url, $cookies);
        $firstValidation = $this->validateResumoAgendaFinanceiroHtml($firstAttempt['status'], $firstAttempt['body']);

        if ($firstValidation['valid']) {
            return $this->parseResumoAgendaFinanceiroSnapshotHtml($firstAttempt['body']);
        }

        if (!$firstValidation['authLike']) {
            throw new Error(
                "Invalid resumoAgenda-financeiro HTML response for agendamento '{$agendamentoId}': " .
                $firstValidation['reason']
            );
        }

        $refreshedCookies = $this->refreshCredentialSessionCookies($credential);

        if (!$refreshedCookies) {
            throw new Error(
                "Web session refresh failed for credential '{$credential->getId()}' while loading agendamento '{$agendamentoId}'."
            );
        }

        $retryAttempt = $this->requestDetailsPage($url, $refreshedCookies);
        $retryValidation = $this->validateResumoAgendaFinanceiroHtml($retryAttempt['status'], $retryAttempt['body']);

        if (!$retryValidation['valid']) {
            throw new Error(
                "Invalid resumoAgenda-financeiro HTML response after retry for agendamento '{$agendamentoId}': " .
                $retryValidation['reason']
            );
        }

        return $this->parseResumoAgendaFinanceiroSnapshotHtml($retryAttempt['body']);
    }

    /**
     * @return array{status:int, body:string, message:string}
     */
    protected function requestDetailsPage(string $url, string $cookies): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return [
                'status' => 0,
                'body' => '',
                'message' => 'Could not initialize cURL.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIE => $cookies,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            ],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            return [
                'status' => 0,
                'body' => '',
                'message' => $curlError !== '' ? $curlError : 'Unknown transport error.',
            ];
        }

        return [
            'status' => $status,
            'body' => (string) $response,
            'message' => 'ok',
        ];
    }

    protected function refreshCredentialSessionCookies(Entity $credential): ?string
    {
        $credentialId = $credential->getId();

        if (!$credentialId) {
            return null;
        }

        $result = $this->healthCheckManager->checkById($credentialId);

        if ($result->status !== HealthCheckResult::STATUS_HEALTHY) {
            $this->log->warning(
                "ClinicaNasNuvensWebClient: health check refresh failed for credential '{$credentialId}': " .
                $result->message
            );

            return null;
        }

        $reloadedCredential = $this->entityManager->getEntityById('Credential', $credentialId);

        if (!$reloadedCredential) {
            return null;
        }

        $config = $this->extractCredentialConfig($reloadedCredential);

        return $this->normalizeNullableString($config['sessionCookies'] ?? null);
    }

    /**
     * @return array{valid:bool, authLike:bool, reason:string}
     */
    protected function validateTargetHtml(int $status, string $html): array
    {
        $normalizedHtml = strtolower($html);

        if ($status !== 200) {
            return [
                'valid' => false,
                'authLike' => $status === 401 || $status === 403,
                'reason' => "HTTP {$status}",
            ];
        }

        if ($normalizedHtml === '') {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => 'empty-response',
            ];
        }

        if (
            str_contains($normalizedHtml, 'attention required') &&
            str_contains($normalizedHtml, 'cloudflare')
        ) {
            return [
                'valid' => false,
                'authLike' => true,
                'reason' => 'cloudflare-challenge',
            ];
        }

        if (
            str_contains($normalizedHtml, 'b2clogin.com') ||
            str_contains($normalizedHtml, 'name="password"') ||
            str_contains($normalizedHtml, 'name="email"') ||
            str_contains($normalizedHtml, 'fazer login')
        ) {
            return [
                'valid' => false,
                'authLike' => true,
                'reason' => 'login-page-detected',
            ];
        }

        if (!str_contains($normalizedHtml, 'detalhesconta') && !str_contains($normalizedHtml, 'abrevia-texto')) {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => 'non-target-html',
            ];
        }

        return [
            'valid' => true,
            'authLike' => false,
            'reason' => 'ok',
        ];
    }

    /**
     * @return array{valid:bool, authLike:bool, reason:string}
     */
    protected function validateResumoAgendaFinanceiroHtml(int $status, string $html): array
    {
        $normalizedHtml = strtolower($html);

        if ($status !== 200) {
            return [
                'valid' => false,
                'authLike' => $status === 401 || $status === 403,
                'reason' => "HTTP {$status}",
            ];
        }

        if ($normalizedHtml === '') {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => 'empty-response',
            ];
        }

        if (
            str_contains($normalizedHtml, 'attention required') &&
            str_contains($normalizedHtml, 'cloudflare')
        ) {
            return [
                'valid' => false,
                'authLike' => true,
                'reason' => 'cloudflare-challenge',
            ];
        }

        if (
            str_contains($normalizedHtml, 'b2clogin.com') ||
            str_contains($normalizedHtml, 'name="password"') ||
            str_contains($normalizedHtml, 'name="email"') ||
            str_contains($normalizedHtml, 'fazer login')
        ) {
            return [
                'valid' => false,
                'authLike' => true,
                'reason' => 'login-page-detected',
            ];
        }

        if (!str_contains($normalizedHtml, 'tab_financeiro')) {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => 'non-target-html',
            ];
        }

        return [
            'valid' => true,
            'authLike' => false,
            'reason' => 'ok',
        ];
    }

    /**
     * @return array{valid:bool, authLike:bool, reason:string}
     */
    protected function validateProcedimentoHtml(int $status, string $html): array
    {
        $normalizedHtml = strtolower($html);

        if ($status !== 200) {
            return [
                'valid' => false,
                'authLike' => $status === 401 || $status === 403,
                'reason' => "HTTP {$status}",
            ];
        }

        if ($normalizedHtml === '') {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => 'empty-response',
            ];
        }

        if (
            str_contains($normalizedHtml, 'attention required') &&
            str_contains($normalizedHtml, 'cloudflare')
        ) {
            return [
                'valid' => false,
                'authLike' => true,
                'reason' => 'cloudflare-challenge',
            ];
        }

        if (
            str_contains($normalizedHtml, 'b2clogin.com') ||
            str_contains($normalizedHtml, 'name="password"') ||
            str_contains($normalizedHtml, 'name="email"') ||
            str_contains($normalizedHtml, 'fazer login')
        ) {
            return [
                'valid' => false,
                'authLike' => true,
                'reason' => 'login-page-detected',
            ];
        }

        if (!str_contains($normalizedHtml, 'id="convenios"') && !str_contains($normalizedHtml, "id='convenios'")) {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => 'non-target-html',
            ];
        }

        return [
            'valid' => true,
            'authLike' => false,
            'reason' => 'ok',
        ];
    }

    /**
     * @return array{valid:bool, authLike:bool, reason:string}
     */
    protected function validateExportacaoListaHtml(int $status, string $html): array
    {
        $normalizedHtml = strtolower($html);

        if ($status !== 200) {
            return [
                'valid' => false,
                'authLike' => $status === 401 || $status === 403,
                'reason' => "HTTP {$status}",
            ];
        }

        if ($normalizedHtml === '') {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => 'empty-response',
            ];
        }

        if (
            str_contains($normalizedHtml, 'attention required') &&
            str_contains($normalizedHtml, 'cloudflare')
        ) {
            return [
                'valid' => false,
                'authLike' => true,
                'reason' => 'cloudflare-challenge',
            ];
        }

        if (
            str_contains($normalizedHtml, 'b2clogin.com') ||
            str_contains($normalizedHtml, 'name="password"') ||
            str_contains($normalizedHtml, 'name="email"') ||
            str_contains($normalizedHtml, 'fazer login')
        ) {
            return [
                'valid' => false,
                'authLike' => true,
                'reason' => 'login-page-detected',
            ];
        }

        if (!str_contains($normalizedHtml, 'lista-exportacao')) {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => 'non-target-html',
            ];
        }

        return [
            'valid' => true,
            'authLike' => false,
            'reason' => 'ok',
        ];
    }

    /**
     * @return array{valid:bool, authLike:bool, reason:string}
     */
    protected function validateExportacaoSalvarResponse(int $status, string $html): array
    {
        $normalizedHtml = strtolower($html);

        // HTTP 200 or 302 with no login page content = success.
        if ($status === 200 || $status === 302) {
            if (
                str_contains($normalizedHtml, 'b2clogin.com') ||
                str_contains($normalizedHtml, 'name="password"') ||
                str_contains($normalizedHtml, 'name="email"') ||
                str_contains($normalizedHtml, 'fazer login')
            ) {
                return [
                    'valid' => false,
                    'authLike' => true,
                    'reason' => 'login-page-detected',
                ];
            }

            if (
                str_contains($normalizedHtml, 'attention required') &&
                str_contains($normalizedHtml, 'cloudflare')
            ) {
                return [
                    'valid' => false,
                    'authLike' => true,
                    'reason' => 'cloudflare-challenge',
                ];
            }

            return [
                'valid' => true,
                'authLike' => false,
                'reason' => 'ok',
            ];
        }

        if ($status === 401 || $status === 403) {
            return [
                'valid' => false,
                'authLike' => true,
                'reason' => "HTTP {$status}",
            ];
        }

        return [
            'valid' => false,
            'authLike' => false,
            'reason' => "HTTP {$status}",
        ];
    }

    /**
     * Parse the exportação lista page HTML to extract file info from the most recent export row.
     *
     * @return array<int, array{codigo: int, dataHoraCriacao: string}>
     */
    protected function parseExportacaoListaHtml(string $html): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        foreach ($document->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $document->removeChild($node);

                break;
            }
        }

        $xpath = new DOMXPath($document);

        // Diagnostic: count table rows.
        $rowCount = $xpath->query('//table[@id="lista-exportacao"]/tbody/tr');
        $this->log->info(
            'parseExportacaoListaHtml: table rows found: ' . ($rowCount ? $rowCount->length : 0) .
            ', html length: ' . strlen($html)
        );

        // Try the primary XPath.
        $dataArquivosRaw = $this->extractSingleNodeAttribute(
            $xpath,
            '//table[@id="lista-exportacao"]/tbody/tr[1]/td[3]//a[contains(@class, "btn-baixar")]',
            'data-arquivos',
        );

        // Fallback: try any a with data-arquivos anywhere in the table.
        if ($dataArquivosRaw === null) {
            $dataArquivosRaw = $this->extractSingleNodeAttribute(
                $xpath,
                '//table[@id="lista-exportacao"]//a[@data-arquivos]',
                'data-arquivos',
            );

            if ($dataArquivosRaw !== null) {
                $this->log->info('parseExportacaoListaHtml: Found data-arquivos via fallback XPath.');
            }
        }

        if ($dataArquivosRaw === null) {
            // Diagnostic: log first row HTML for debugging.
            $firstRow = $xpath->query('//table[@id="lista-exportacao"]/tbody/tr[1]');

            if ($firstRow && $firstRow->length > 0) {
                $rowHtml = $document->saveHTML($firstRow->item(0));
                $this->log->info(
                    'parseExportacaoListaHtml: No data-arquivos found. First row HTML (truncated): ' .
                    substr((string) $rowHtml, 0, 500)
                );
            } else {
                $this->log->info('parseExportacaoListaHtml: No table rows found at all.');
            }

            return [];
        }

        $decoded = json_decode($dataArquivosRaw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $result = [];

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $codigo = $item['codigo'] ?? null;
            $dataHoraCriacao = $item['dataHoraCriacao'] ?? null;

            if (!is_int($codigo) && !is_string($codigo)) {
                continue;
            }

            if (!is_string($dataHoraCriacao)) {
                continue;
            }

            $result[] = [
                'codigo' => (int) $codigo,
                'dataHoraCriacao' => $dataHoraCriacao,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseDetalhesContaHtml(string $html, string $faturamentoId): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        // Remove the injected encoding node so it doesn't affect XPath queries.
        foreach ($document->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $document->removeChild($node);

                break;
            }
        }

        $xpath = new DOMXPath($document);

        $documento = $this->extractSingleNodeText($xpath, self::XPATH_DOCUMENTO);
        $dataFaturamentoRaw = $this->extractSingleNodeText($xpath, self::XPATH_DATA_FATURAMENTO);
        $profissionalNome = $this->extractSingleNodeText($xpath, self::XPATH_PROFISSIONAL_NOME);
        $conta = $this->extractSingleNodeText($xpath, self::XPATH_CONTA);
        $valorRaw = $this->extractSingleNodeText($xpath, self::XPATH_VALOR);
        $parcela = $this->extractSingleNodeText($xpath, self::XPATH_PARCELA);
        $dataVencimentoRaw = $this->extractSingleNodeText($xpath, self::XPATH_DATA_VENCIMENTO);
        $description = $this->extractSingleNodeText($xpath, self::XPATH_DESCRIPTION);
        $agendamentoIdRaw = $this->extractSingleNodeText($xpath, self::XPATH_AGENDAMENTO_ID);

        return [
            'faturamentoId' => $this->normalizeNullableString($faturamentoId),
            'documento' => $this->normalizeNullableString($documento),
            'dataFaturamento' => $this->normalizeBrazilianDate($dataFaturamentoRaw),
            'profissionalNome' => $this->normalizeNullableString($profissionalNome),
            'conta' => $this->normalizeNullableString($conta),
            'valor' => $this->normalizeBrazilianMoney($valorRaw),
            'valorCurrency' => 'BRL',
            'parcela' => $this->normalizeNullableString($parcela),
            'dataVencimento' => $this->normalizeBrazilianDate($dataVencimentoRaw),
            'description' => $this->normalizeNullableString($description),
            'agendamentoId' => $this->normalizeAgendamentoId($agendamentoIdRaw),
        ];
    }

    /**
     * @return string[]
     */
    protected function parseResumoAgendaFinanceiroHtml(string $html): array
    {
        return $this->parseResumoAgendaFinanceiroSnapshotHtml($html)['faturamentoIdList'];
    }

    /**
     * @return array{faturamentoIdList: string[], statusFaturamento: ?string}
     */
    protected function parseResumoAgendaFinanceiroSnapshotHtml(string $html): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        foreach ($document->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $document->removeChild($node);

                break;
            }
        }

        $xpath = new DOMXPath($document);
        $nodeList = $xpath->query('//*[@id="tab_financeiro"]/div[1]/div/table/tbody/tr/td[7]/div/a[@data-faturamento]');

        $faturamentoIdList = [];

        if ($nodeList) {
            foreach ($nodeList as $node) {
                if (!($node instanceof \DOMElement)) {
                    continue;
                }

                $faturamentoId = $this->normalizeNullableString($node->getAttribute('data-faturamento'));

                if ($faturamentoId !== null) {
                    $faturamentoIdList[] = $faturamentoId;
                }
            }
        }

        $faturamentoIdList = array_values(array_unique($faturamentoIdList));
        $alertText = $this->extractSingleNodeText($xpath, self::XPATH_RESUMO_AGENDA_FINANCEIRO_ALERT_H1);

        return [
            'faturamentoIdList' => $faturamentoIdList,
            'statusFaturamento' => $this->resolveStatusFaturamento($faturamentoIdList, $alertText),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseProcedimentoConvenioPricingHtml(string $html): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        foreach ($document->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $document->removeChild($node);

                break;
            }
        }

        $xpath = new DOMXPath($document);
        $rows = $xpath->query("//*[@id='convenios']/tbody/tr");

        if (!$rows) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            if (!($row instanceof \DOMElement)) {
                continue;
            }

            $codigoTipoProcedimentoConvenio = $this->extractSingleNodeAttribute(
                $xpath,
                ".//input[@type='hidden' and contains(@name, '.codigoTipoProcedimentoConvenio')][1]",
                'value',
                $row,
            ) ?? $this->extractSingleNodeAttribute($xpath, "td[2]/input[@type='hidden'][1]", 'value', $row);
            $codigoTipoConvenio = $this->normalizeNullableString(
                $this->extractSingleNodeAttribute(
                    $xpath,
                    ".//input[@type='hidden' and contains(@name, '.codigoTipoConvenio')][1]",
                    'value',
                    $row,
                ) ?? $this->extractSingleNodeAttribute($xpath, "td[2]/input[@type='hidden'][2]", 'value', $row)
            );

            if ($codigoTipoConvenio === null) {
                continue;
            }

            $checkbox = $xpath->query(
                ".//input[@type='checkbox' and contains(@name, '.ativo')][1]",
                $row,
            )?->item(0);

            if (!($checkbox instanceof \DOMElement)) {
                $checkbox = $xpath->query("td[2]/input[@type='checkbox'][1]", $row)?->item(0);
            }

            $precoPacienteRaw = $this->extractSingleNodeAttribute(
                $xpath,
                ".//input[@type='text' and contains(@name, '.valorPaciente')][1]",
                'value',
                $row,
            ) ?? $this->extractSingleNodeAttribute($xpath, "td[3]/input[@type='text'][1]", 'value', $row);

            $precoConvenioRaw = $this->extractSingleNodeAttribute(
                $xpath,
                ".//input[@type='text' and contains(@name, '.valorConvenio')][1]",
                'value',
                $row,
            ) ?? $this->extractSingleNodeAttribute($xpath, "td[4]/input[@type='text'][1]", 'value', $row);

            $result[] = [
                'codigoTipoProcedimentoConvenio' => $codigoTipoProcedimentoConvenio,
                'codigoTipoConvenio' => $codigoTipoConvenio,
                'isActive' => $checkbox instanceof \DOMElement && $checkbox->hasAttribute('checked'),
                'precoPaciente' => $this->normalizeBrazilianMoney($precoPacienteRaw),
                'precoConvenio' => $this->normalizeBrazilianMoney($precoConvenioRaw),
            ];
        }

        return $result;
    }

    /**
     * @param string[] $faturamentoIdList
     */
    private function resolveStatusFaturamento(array $faturamentoIdList, ?string $alertText): ?string
    {
        $normalizedAlert = $this->normalizeStatusText($alertText);

        if ($normalizedAlert === 'esse faturamento foi descartado!') {
            return self::STATUS_FATURAMENTO_CANCELADO;
        }

        if ($normalizedAlert === 'nenhum resultado encontrado') {
            return self::STATUS_FATURAMENTO_SEM_REGISTRO;
        }

        if ($faturamentoIdList !== []) {
            return self::STATUS_FATURAMENTO_FATURADO;
        }

        return null;
    }

    private function normalizeStatusText(?string $value): ?string
    {
        $normalized = $this->normalizeNullableString($value);

        if ($normalized === null) {
            return null;
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
    }

    private function extractSingleNodeText(DOMXPath $xpath, string $selector): ?string
    {
        $nodeList = $xpath->query($selector);

        if (!$nodeList || $nodeList->length === 0) {
            return null;
        }

        $value = $nodeList->item(0)?->textContent;

        return $value !== null ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
    }

    private function extractSingleNodeAttribute(
        DOMXPath $xpath,
        string $selector,
        string $attribute,
        ?\DOMNode $contextNode = null,
    ): ?string {
        $nodeList = $xpath->query($selector, $contextNode);

        if (!$nodeList || $nodeList->length === 0) {
            return null;
        }

        $node = $nodeList->item(0);

        if (!($node instanceof \DOMElement)) {
            return null;
        }

        return $this->normalizeNullableString($node->getAttribute($attribute));
    }

    private function normalizeBrazilianDate(?string $value): ?string
    {
        $normalized = $this->normalizeNullableString($value);

        if (!$normalized) {
            return null;
        }

        if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $normalized, $matches)) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = (int) $matches[3];

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function normalizeBrazilianMoney(?string $value): ?float
    {
        $normalized = $this->normalizeNullableString($value);

        if (!$normalized) {
            return null;
        }

        $sanitized = preg_replace('/[^\d,.-]/', '', $normalized);

        if (!is_string($sanitized) || $sanitized === '') {
            return null;
        }

        $hasComma = str_contains($sanitized, ',');
        $hasDot = str_contains($sanitized, '.');

        if ($hasComma && $hasDot) {
            // pt-BR format (e.g. 1.234,56)
            $sanitized = str_replace('.', '', $sanitized);
            $sanitized = str_replace(',', '.', $sanitized);
        } elseif ($hasComma) {
            // comma decimal format (e.g. 123,45)
            $sanitized = str_replace(',', '.', $sanitized);
        } else {
            // dot decimal format (e.g. 123.45)
            $sanitized = str_replace(',', '', $sanitized);
        }

        if (!is_numeric($sanitized)) {
            return null;
        }

        return round((float) $sanitized, 2);
    }

    private function normalizeAgendamentoId(?string $value): ?string
    {
        $normalized = $this->normalizeNullableString($value);

        if (!$normalized) {
            return null;
        }

        $normalized = ltrim($normalized, '#');

        return $this->normalizeNullableString($normalized);
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalizedWhitespace = preg_replace('/\s+/u', ' ', trim($decoded));

        if (!is_string($normalizedWhitespace)) {
            return null;
        }

        return $normalizedWhitespace !== '' ? $normalizedWhitespace : null;
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
                    "ClinicaNasNuvensWebClient: failed to resolve credential '{$credentialId}', fallback to raw config. " .
                    $e->getMessage()
                );
            }
        }

        $config = $credential->get('config');

        if (is_string($config)) {
            $decoded = json_decode($config, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return array{status:int, body:string, message:string}
     */
    private function requestPostPage(string $url, string $cookies): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return [
                'status' => 0,
                'body' => '',
                'message' => 'Could not initialize cURL.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIE => $cookies,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            return [
                'status' => 0,
                'body' => '',
                'message' => $curlError !== '' ? $curlError : 'Unknown transport error.',
            ];
        }

        return [
            'status' => $status,
            'body' => (string) $response,
            'message' => 'ok',
        ];
    }

    /**
     * Download a file to disk via cURL streaming (CURLOPT_FILE).
     *
     * @return array{valid:bool, authLike:bool, reason:string}
     */
    private function requestDownloadToFile(string $url, string $cookies, string $destPath): array
    {
        $fp = @fopen($destPath, 'wb');

        if ($fp === false) {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => "Could not open destination file for writing: {$destPath}",
            ];
        }

        $ch = curl_init($url);

        if ($ch === false) {
            fclose($fp);

            return [
                'valid' => false,
                'authLike' => false,
                'reason' => 'Could not initialize cURL.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => self::DOWNLOAD_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIE => $cookies,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            ],
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($curlError !== '') {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => "cURL error: {$curlError}",
            ];
        }

        if ($status === 401 || $status === 403) {
            return [
                'valid' => false,
                'authLike' => true,
                'reason' => "HTTP {$status}",
            ];
        }

        if ($status !== 200) {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => "HTTP {$status}",
            ];
        }

        // Validate file exists and has content.
        if (!file_exists($destPath) || filesize($destPath) === 0) {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => 'Downloaded file is empty.',
            ];
        }

        // Check zip magic bytes (PK\x03\x04).
        $header = file_get_contents($destPath, false, null, 0, 4);

        if ($header === false || strlen($header) < 4) {
            return [
                'valid' => false,
                'authLike' => false,
                'reason' => 'Cannot read file header.',
            ];
        }

        if ($header !== "\x50\x4B\x03\x04") {
            // Likely an HTML auth page was downloaded instead of a zip.
            $snippet = (string) file_get_contents($destPath, false, null, 0, 512);
            $snippetLower = strtolower($snippet);

            $isAuth = str_contains($snippetLower, 'b2clogin.com') ||
                str_contains($snippetLower, 'name="password"') ||
                str_contains($snippetLower, 'fazer login') ||
                (str_contains($snippetLower, 'attention required') && str_contains($snippetLower, 'cloudflare'));

            return [
                'valid' => false,
                'authLike' => $isAuth,
                'reason' => $isAuth ? 'auth-page-downloaded' : 'invalid-zip-file',
            ];
        }

        return [
            'valid' => true,
            'authLike' => false,
            'reason' => 'ok',
        ];
    }
}
