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
    private const TIMEOUT_SECONDS = 20;
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

        $sanitized = str_replace('.', '', $sanitized);
        $sanitized = str_replace(',', '.', $sanitized);

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
}
