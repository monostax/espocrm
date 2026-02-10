<?php

namespace Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckers;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Health checker for form-based authentication credentials.
 *
 * Verifies the credential by:
 * 1. Testing the current session with a request to the test URL
 * 2. If session is invalid, re-authenticating using stored credentials
 * 3. Updating session cookies on successful re-authentication
 * 4. Retrying the test with new session cookies
 */
class FormAuthHealthChecker implements HealthCheckerInterface
{
    private const TIMEOUT_SECONDS = 30;
    private const DEFAULT_TEST_URL = 'https://www.simplesagenda.com.br/agendamento.php';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    public function __construct(
        private Log $log,
        private EntityManager $entityManager,
    ) {}

    public function check(stdClass $resolvedConfig, Entity $credential, Entity $credentialType): HealthCheckResult
    {
        $username = $resolvedConfig->username ?? null;
        $password = $resolvedConfig->password ?? null;
        $loginUrl = $resolvedConfig->loginUrl ?? null;
        $testUrl = $resolvedConfig->testUrl ?? self::DEFAULT_TEST_URL;
        $sessionCookies = $resolvedConfig->sessionCookies ?? null;
        $usernameField = $resolvedConfig->usernameField ?? 'login';
        $passwordField = $resolvedConfig->passwordField ?? 'senha';
        $additionalFields = $resolvedConfig->additionalFields ?? new stdClass();

        if (!$username || !$password || !$loginUrl) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Missing required credentials: username, password, or loginUrl.',
            );
        }

        // First, try with existing session cookies
        if ($sessionCookies) {
            $result = $this->testSession($testUrl, $sessionCookies);

            if ($result['status'] === 'healthy') {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_HEALTHY,
                    "Session valid. {$result['message']}",
                    $result['responseTimeMs'] ?? null,
                );
            }

            $this->log->debug("FormAuth health check: Session invalid for credential '{$credential->getId()}', attempting re-authentication.");
        }

        // Attempt re-authentication
        $authResult = $this->authenticate(
            $loginUrl,
            $username,
            $password,
            $usernameField,
            $passwordField,
            $additionalFields,
        );

        if (!$authResult['success']) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                "Authentication failed: {$authResult['message']}",
            );
        }

        // Update session cookies on the credential
        $newCookies = $authResult['cookies'] ?? null;
        if ($newCookies) {
            $this->updateSessionCookies($credential, $newCookies);
        }

        // Test the new session
        $testResult = $this->testSession($testUrl, $newCookies);

        if ($testResult['status'] === 'healthy') {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_HEALTHY,
                "Re-authenticated successfully. {$testResult['message']}",
                $testResult['responseTimeMs'] ?? null,
            );
        }

        return new HealthCheckResult(
            HealthCheckResult::STATUS_UNHEALTHY,
            "Re-authenticated but session test failed: {$testResult['message']}",
            $testResult['responseTimeMs'] ?? null,
        );
    }

    /**
     * Test if the current session is valid by making a request to the test URL.
     *
     * @return array{status: string, message: string, responseTimeMs?: int}
     */
    private function testSession(string $testUrl, string $cookies): array
    {
        $startTime = hrtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $testUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $endTime = hrtime(true);
        $responseTimeMs = (int) (($endTime - $startTime) / 1_000_000);

        if ($error) {
            return [
                'status' => 'unhealthy',
                'message' => "Connection failed: {$error}",
                'responseTimeMs' => $responseTimeMs,
            ];
        }

        if ($httpCode === 200) {
            return [
                'status' => 'healthy',
                'message' => "HTTP 200 OK ({$responseTimeMs}ms)",
                'responseTimeMs' => $responseTimeMs,
            ];
        }

        return [
            'status' => 'unhealthy',
            'message' => "HTTP {$httpCode} ({$responseTimeMs}ms)",
            'responseTimeMs' => $responseTimeMs,
        ];
    }

    /**
     * Authenticate using the form login.
     *
     * Performs a two-step flow:
     * 1. GET the site origin to establish a server-side session (picks up session cookies)
     * 2. POST credentials to the loginUrl with the session cookies to authenticate
     *
     * Uses CURLOPT_HEADERFUNCTION for reliable cookie extraction (cookie jar
     * files are unreliable with HTTP/2 in some curl versions).
     *
     * @return array{success: bool, message: string, cookies?: string}
     */
    private function authenticate(
        string $loginUrl,
        string $username,
        string $password,
        string $usernameField,
        string $passwordField,
        stdClass $additionalFields,
    ): array {
        $origin = parse_url($loginUrl, PHP_URL_SCHEME) . '://' . parse_url($loginUrl, PHP_URL_HOST);

        // Step 1: GET the site origin to establish a server-side session.
        // Many sites (e.g. PHP apps behind Cloudflare) require an existing
        // session before the login POST will authenticate. We hit the origin
        // root rather than the loginUrl, because the loginUrl may be a pure
        // API endpoint (e.g. crud.php) that doesn't start a session on GET.
        $preAuthCookies = [];
        $preAuthUrl = $origin . '/';

        $this->log->debug("FormAuth: Pre-auth GET to {$preAuthUrl}");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $preAuthUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        ]);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$preAuthCookies) {
            if (preg_match('/^set-cookie:\s*([^=]+)=([^;]+)/i', $header, $m)) {
                $preAuthCookies[trim($m[1])] = trim($m[2]);
            }

            return strlen($header);
        });

        $preAuthResponse = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'message' => "Pre-authentication request failed: {$error}",
            ];
        }

        // Detect Cloudflare challenge on pre-auth request
        if (str_contains($preAuthResponse, 'Attention Required') && str_contains($preAuthResponse, 'Cloudflare')) {
            return [
                'success' => false,
                'message' => 'Request blocked by Cloudflare bot protection. The target site may require IP whitelisting or manual browser verification.',
            ];
        }

        $this->log->debug('FormAuth: Pre-auth cookies obtained: ' . implode(', ', array_keys($preAuthCookies)));

        // Step 2: POST credentials with session cookies.
        $formData = [
            $usernameField => $username,
            $passwordField => $password,
        ];

        foreach ($additionalFields as $key => $value) {
            $formData[$key] = $value;
        }

        $postFields = http_build_query($formData);
        $postCookies = [];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_COOKIE, $this->formatCookiesForHeader($preAuthCookies));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Accept: application/xml, text/xml, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            'Origin: ' . $origin,
            'Referer: ' . $loginUrl,
        ]);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$postCookies) {
            if (preg_match('/^set-cookie:\s*([^=]+)=([^;]+)/i', $header, $m)) {
                $postCookies[trim($m[1])] = trim($m[2]);
            }

            return strlen($header);
        });

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'message' => "Connection failed: {$error}",
            ];
        }

        // Detect Cloudflare challenge on login POST
        if (str_contains($response, 'Attention Required') && str_contains($response, 'Cloudflare')) {
            return [
                'success' => false,
                'message' => 'Request blocked by Cloudflare bot protection. The target site may require IP whitelisting or manual browser verification.',
            ];
        }

        // Merge cookies: pre-auth session + any new cookies from login POST
        $allCookies = array_merge($preAuthCookies, $postCookies);

        if (empty($allCookies)) {
            return [
                'success' => false,
                'message' => 'No session cookies received from authentication endpoint.',
            ];
        }

        $cookieString = $this->formatCookiesForHeader($allCookies);

        return [
            'success' => true,
            'message' => 'Authentication successful',
            'cookies' => $cookieString,
        ];
    }

    /**
     * Format cookies array for Cookie header.
     *
     * @param array<string, string> $cookies
     */
    private function formatCookiesForHeader(array $cookies): string
    {
        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = "{$name}={$value}";
        }
        return implode('; ', $parts);
    }

    /**
     * Update session cookies on the credential entity.
     */
    private function updateSessionCookies(Entity $credential, string $cookies): void
    {
        try {
            $configRaw = $credential->get('config') ?? '{}';
            $config = json_decode($configRaw, true) ?? [];

            $config['sessionCookies'] = $cookies;

            $credential->set('config', json_encode($config));
            $this->entityManager->saveEntity($credential);

            $this->log->debug("Updated session cookies for credential '{$credential->getId()}'");
        } catch (\Throwable $e) {
            $this->log->error("Failed to update session cookies for credential '{$credential->getId()}': " . $e->getMessage());
        }
    }
}
