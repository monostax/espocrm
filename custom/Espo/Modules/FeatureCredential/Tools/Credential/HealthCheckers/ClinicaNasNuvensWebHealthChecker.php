<?php

namespace Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckers;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Health checker for Clínica nas Nuvens web credentials (code: clinicaNasNuvens-web).
 *
 * Uses Azure B2C OIDC flow to authenticate:
 * 1. GET /login to establish app session
 * 2. GET /b2c/login-page?usuario=<email> to get B2C authorize URL
 * 3. GET B2C authorize URL to get login page (CSRF + transId)
 * 4. POST SelfAsserted with credentials
 * 5. GET confirmed endpoint -> 302 to /b2c/login?code=...
 * 6. GET /b2c/login?code=... -> HTML with hidden form posting to /perform_login_b2c
 * 7. POST /perform_login_b2c with code+state -> authenticated session
 */
class ClinicaNasNuvensWebHealthChecker implements HealthCheckerInterface
{
    private const TIMEOUT_SECONDS = 30;
    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
        '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    private const APP_BASE = 'https://app.clinicanasnuvens.com.br';

    public function __construct(
        private Log $log,
        private EntityManager $entityManager,
    ) {}

    public function check(stdClass $resolvedConfig, Entity $credential, Entity $credentialType): HealthCheckResult
    {
        $username = $resolvedConfig->username ?? null;
        $password = $resolvedConfig->password ?? null;
        $testUrl = $resolvedConfig->testUrl ?? (self::APP_BASE . '/agenda/index');
        $sessionCookies = $resolvedConfig->sessionCookies ?? null;

        if (!$username || !$password) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Missing required credentials: username or password.',
            );
        }

        $startTime = hrtime(true);

        // Test existing session first
        if ($sessionCookies) {
            $testResult = $this->testSession($testUrl, $sessionCookies);

            if ($testResult['healthy']) {
                $ms = $this->elapsedMs($startTime);

                return new HealthCheckResult(
                    HealthCheckResult::STATUS_HEALTHY,
                    "Session valid. ({$ms}ms)",
                    $ms,
                );
            }

            $this->log->debug(
                "ClinicaNasNuvensWebHealthChecker: Session expired for credential '{$credential->getId()}', re-authenticating."
            );
        }

        // Re-authenticate via B2C flow
        $authResult = $this->authenticateB2c($username, $password);

        if (!$authResult['success']) {
            $ms = $this->elapsedMs($startTime);

            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                "B2C authentication failed: {$authResult['error']} ({$ms}ms)",
                $ms,
            );
        }

        $newCookies = $authResult['sessionCookies'];

        // Persist new session cookies
        $this->updateSessionCookies($credential, $newCookies);

        // Verify the new session
        $verifyResult = $this->testSession($testUrl, $newCookies);
        $ms = $this->elapsedMs($startTime);

        if ($verifyResult['healthy']) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_HEALTHY,
                "Re-authenticated successfully. ({$ms}ms)",
                $ms,
            );
        }

        return new HealthCheckResult(
            HealthCheckResult::STATUS_UNHEALTHY,
            "Re-authenticated but session test failed: {$verifyResult['reason']} ({$ms}ms)",
            $ms,
        );
    }

    /**
     * @return array{healthy: bool, reason: string}
     */
    private function testSession(string $testUrl, string $cookies): array
    {
        $ch = curl_init($testUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIE => $cookies,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['healthy' => false, 'reason' => "Connection error: {$error}"];
        }

        if ($status !== 200) {
            return ['healthy' => false, 'reason' => "HTTP {$status}"];
        }

        // Detect login page redirect (CNN returns 200 for login page)
        $effectivePath = strtolower(parse_url($effectiveUrl, PHP_URL_PATH) ?? '');

        if (str_contains($effectivePath, '/login')) {
            return ['healthy' => false, 'reason' => 'Redirected to login page'];
        }

        $lower = strtolower($body ?: '');

        if (
            (str_contains($lower, 'b2clogin.com') || str_contains($lower, 'fazer login')) &&
            str_contains($lower, '<title>') && str_contains($lower, 'login')
        ) {
            return ['healthy' => false, 'reason' => 'Login page content detected'];
        }

        return ['healthy' => true, 'reason' => 'ok'];
    }

    /**
     * @return array{success: bool, sessionCookies?: string, error?: string}
     */
    private function authenticateB2c(string $username, string $password): array
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'cnn_b2c_');

        try {
            return $this->doB2cFlow($username, $password, $cookieJar);
        } finally {
            if (file_exists($cookieJar)) {
                @unlink($cookieJar);
            }
        }
    }

    /**
     * @return array{success: bool, sessionCookies?: string, error?: string}
     */
    private function doB2cFlow(string $username, string $password, string $cookieJar): array
    {
        // Step 1: GET /login to establish app session
        $this->curlGet(self::APP_BASE . '/login', $cookieJar);

        // Step 2: GET /b2c/login-page?usuario=<email> to get B2C URL
        $b2cUrlRaw = $this->curlGet(
            self::APP_BASE . '/b2c/login-page?usuario=' . urlencode($username),
            $cookieJar,
            ['X-Requested-With: XMLHttpRequest'],
        );

        $b2cUrl = json_decode(trim($b2cUrlRaw));

        if (!$b2cUrl || !is_string($b2cUrl)) {
            return ['success' => false, 'error' => 'No B2C URL returned from /b2c/login-page'];
        }

        // Step 3: GET B2C authorize page
        $b2cPage = $this->curlGet($b2cUrl, $cookieJar);

        if (!preg_match('/var\s+SETTINGS\s*=\s*({.*?});\s*\n/s', $b2cPage, $m)) {
            return ['success' => false, 'error' => 'Could not parse B2C SETTINGS'];
        }

        $settings = json_decode($m[1], true);
        $csrf = $settings['csrf'] ?? '';
        $transId = $settings['transId'] ?? '';
        $tenant = $settings['hosts']['tenant'] ?? '';

        if (!$csrf || !$transId || !$tenant) {
            return ['success' => false, 'error' => 'Missing B2C CSRF/transId/tenant'];
        }

        $b2cBase = 'https://clinicanasnuvens.b2clogin.com';

        // Step 4: POST SelfAsserted
        $selfAssertedUrl = $b2cBase . $tenant .
            '/SelfAsserted?tx=' . urlencode($transId) . '&p=B2C_1_login';

        $selfAssertedBody = $this->curlPost(
            $selfAssertedUrl,
            http_build_query(['request_type' => 'RESPONSE', 'email' => $username, 'password' => $password]),
            $cookieJar,
            [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-TOKEN: ' . $csrf,
                'X-Requested-With: XMLHttpRequest',
                'Origin: ' . $b2cBase,
            ],
            false,
        );

        $selfAssertedData = json_decode($selfAssertedBody ?? '', true);

        if (!isset($selfAssertedData['status']) || $selfAssertedData['status'] !== '200') {
            return ['success' => false, 'error' => 'B2C SelfAsserted failed: ' . ($selfAssertedBody ?? 'empty')];
        }

        // Step 5: GET confirmed -> 302 redirect with auth code
        $confirmedUrl = $b2cBase . $tenant .
            '/api/CombinedSigninAndSignup/confirmed?' .
            'rememberMe=false&csrf_token=' . urlencode($csrf) .
            '&tx=' . urlencode($transId) . '&p=B2C_1_login';

        $redirectUrl = null;

        $ch = curl_init($confirmedUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$redirectUrl) {
                if (preg_match('/^location:\s*(.+)/i', $header, $m)) {
                    $redirectUrl = trim($m[1]);
                }

                return strlen($header);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);

        if (!$redirectUrl) {
            return ['success' => false, 'error' => 'No redirect from B2C confirmed endpoint'];
        }

        // Step 6: GET /b2c/login?code=... -> HTML with hidden form
        $callbackBody = $this->curlGet($redirectUrl, $cookieJar);

        if (
            !preg_match('/name="code"\s+value="([^"]+)"/', $callbackBody, $codeMatch) ||
            !preg_match('/name="state"\s+value="([^"]+)"/', $callbackBody, $stateMatch)
        ) {
            return ['success' => false, 'error' => 'Could not parse hidden form from /b2c/login callback'];
        }

        $code = $codeMatch[1];
        $state = $stateMatch[1];

        // Step 7: POST /perform_login_b2c (the JS auto-submit)
        $this->curlPost(
            self::APP_BASE . '/perform_login_b2c',
            http_build_query(['code' => $code, 'state' => $state]),
            $cookieJar,
            ['Content-Type: application/x-www-form-urlencoded'],
            true,
        );

        // Extract SESSION cookie from jar
        $jarContent = file_get_contents($cookieJar);

        if (!preg_match('/\bSESSION\s+(\S+)/', $jarContent, $sessionMatch)) {
            return ['success' => false, 'error' => 'No SESSION cookie after perform_login_b2c'];
        }

        return [
            'success' => true,
            'sessionCookies' => 'SESSION=' . $sessionMatch[1],
        ];
    }

    private function curlGet(string $url, string $cookieJar, array $headers = []): string
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
        ]);

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $result = curl_exec($ch);
        curl_close($ch);

        return is_string($result) ? $result : '';
    }

    private function curlPost(
        string $url,
        string $postFields,
        string $cookieJar,
        array $headers = [],
        bool $followRedirects = false,
    ): string {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
        ]);

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $result = curl_exec($ch);
        curl_close($ch);

        return is_string($result) ? $result : '';
    }

    private function updateSessionCookies(Entity $credential, string $newCookies): void
    {
        $credentialId = $credential->getId();

        $raw = $credential->get('config');

        if (is_string($raw)) {
            $config = json_decode($raw, true) ?? [];
        } elseif (is_object($raw)) {
            $config = (array) $raw;
        } else {
            $config = [];
        }

        $config['sessionCookies'] = $newCookies;

        try {
            $query = $this->entityManager
                ->getQueryBuilder()
                ->update()
                ->in('Credential')
                ->set(['config' => json_encode((object) $config)])
                ->where(['id' => $credentialId])
                ->build();

            $this->entityManager->getQueryExecutor()->execute($query);

            $this->log->info(
                "ClinicaNasNuvensWebHealthChecker: Updated session cookies for credential '{$credentialId}'."
            );
        } catch (\Throwable $e) {
            $this->log->error(
                "ClinicaNasNuvensWebHealthChecker: Failed to persist session cookies for '{$credentialId}': " .
                $e->getMessage()
            );
        }
    }

    private function elapsedMs(int $startHrtime): int
    {
        return (int) ((hrtime(true) - $startHrtime) / 1_000_000);
    }
}
