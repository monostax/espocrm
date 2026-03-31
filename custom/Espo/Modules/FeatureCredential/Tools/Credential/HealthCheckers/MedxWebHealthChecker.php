<?php

namespace Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckers;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Health checker for MEDX web credentials (code: medx-web).
 *
 * Uses the MEDX unified login flow to authenticate:
 * 1. GET /api/LoginUnificado/VerificaEmailCripto?Email=<email>&dbId= to verify email and get SoftwareId
 * 2. GET /api/security/getkeys to obtain RSA public key for password encryption
 * 3. RSA-encrypt the password client-side (OAEP SHA-1 padding)
 * 4. POST /api/LoginUnificado/loginV3 with encrypted credentials -> returns Bearer token
 * 5. GET /api/security/getcurrentuser with Bearer token to verify session
 */
class MedxWebHealthChecker implements HealthCheckerInterface
{
    private const TIMEOUT_SECONDS = 30;
    private const USER_AGENT =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
        '(KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36';
    private const API_BASE = 'https://v65.medx.med.br';

    public function __construct(
        private Log $log,
        private EntityManager $entityManager,
    ) {}

    public function check(stdClass $resolvedConfig, Entity $credential, Entity $credentialType): HealthCheckResult
    {
        $username = $resolvedConfig->username ?? null;
        $password = $resolvedConfig->password ?? null;
        $testUrl = $resolvedConfig->testUrl ?? (self::API_BASE . '/api/security/getcurrentuser');
        $bearerToken = $resolvedConfig->bearerToken ?? null;

        if (!$username || !$password) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Missing required credentials: username or password.',
            );
        }

        $startTime = hrtime(true);

        // Test existing token first
        if ($bearerToken) {
            $testResult = $this->testSession($testUrl, $bearerToken);

            if ($testResult['healthy']) {
                $ms = $this->elapsedMs($startTime);

                return new HealthCheckResult(
                    HealthCheckResult::STATUS_HEALTHY,
                    "Session valid. ({$ms}ms)",
                    $ms,
                );
            }

            $this->log->debug(
                "MedxWebHealthChecker: Token expired for credential '{$credential->getId()}', re-authenticating."
            );
        }

        // Re-authenticate via MEDX login flow
        $authResult = $this->authenticate($username, $password);

        if (!$authResult['success']) {
            $ms = $this->elapsedMs($startTime);

            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                "MEDX authentication failed: {$authResult['error']} ({$ms}ms)",
                $ms,
            );
        }

        $newToken = $authResult['bearerToken'];
        $newCookies = $authResult['sessionCookies'] ?? '';

        // Persist new bearer token and session cookies
        $this->updateTokenAndCookies($credential, $newToken, $newCookies);

        // Verify the new session
        $verifyResult = $this->testSession($testUrl, $newToken);
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
    private function testSession(string $testUrl, string $bearerToken): array
    {
        $ch = curl_init($testUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $bearerToken,
                'Accept: application/json, text/plain, */*',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['healthy' => false, 'reason' => "Connection error: {$error}"];
        }

        if ($status === 401 || $status === 403) {
            return ['healthy' => false, 'reason' => "HTTP {$status} - unauthorized"];
        }

        if ($status === 302 || $status === 303) {
            return ['healthy' => false, 'reason' => "HTTP {$status} - redirected (session expired)"];
        }

        if ($status !== 200) {
            return ['healthy' => false, 'reason' => "HTTP {$status}"];
        }

        // Verify the response is valid JSON and not an error page
        $data = json_decode($body ?: '', true);

        if ($data === null) {
            $lower = strtolower($body ?: '');

            if (str_contains($lower, 'login') || str_contains($lower, '<html')) {
                return ['healthy' => false, 'reason' => 'Login page content detected'];
            }

            return ['healthy' => false, 'reason' => 'Invalid JSON response'];
        }

        return ['healthy' => true, 'reason' => 'ok'];
    }

    /**
     * @return array{success: bool, bearerToken?: string, sessionCookies?: string, error?: string}
     */
    private function authenticate(string $username, string $password): array
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'medx_auth_');

        try {
            return $this->doLoginFlow($username, $password, $cookieJar);
        } finally {
            if (file_exists($cookieJar)) {
                @unlink($cookieJar);
            }
        }
    }

    /**
     * @return array{success: bool, bearerToken?: string, sessionCookies?: string, error?: string}
     */
    private function doLoginFlow(string $username, string $password, string $cookieJar): array
    {
        // Step 1: Verify email and get SoftwareId (dbId)
        $verifyUrl = self::API_BASE . '/api/LoginUnificado/VerificaEmailCripto?Email=' . urlencode($username) . '&dbId=';
        $verifyResponse = $this->curlGet($verifyUrl, $cookieJar);

        if (!$verifyResponse) {
            return ['success' => false, 'error' => 'No response from VerificaEmailCripto'];
        }

        // Parse the SoftwareId from the response
        // Response can be: "Ok:Success:SoftwareId:12345" or "Ok:JSON:[...]" for multiple clinics
        $dbId = $this->parseSoftwareId($verifyResponse);

        if ($dbId === null) {
            return ['success' => false, 'error' => 'Could not parse SoftwareId from VerificaEmailCripto: ' . $verifyResponse];
        }

        // Step 2: Get RSA public key for password encryption
        $keysResponse = $this->curlGet(self::API_BASE . '/api/security/getkeys', $cookieJar);

        if (!$keysResponse) {
            return ['success' => false, 'error' => 'No response from getkeys'];
        }

        $keysData = json_decode($keysResponse, true);

        if (!$keysData || !isset($keysData['PublicKey']) || !isset($keysData['KeyId'])) {
            return ['success' => false, 'error' => 'Invalid getkeys response: ' . $keysResponse];
        }

        $publicKeyXml = $keysData['PublicKey'];
        $keyId = $keysData['KeyId'];

        // Step 3: RSA-encrypt the password
        $encryptedPassword = $this->rsaEncryptPassword($password, $publicKeyXml);

        if ($encryptedPassword === null) {
            return ['success' => false, 'error' => 'Failed to RSA-encrypt password'];
        }

        // Step 4: Get external IP (optional, best-effort)
        $ip = $this->getExternalIp();

        // Step 5: POST login with encrypted credentials
        // dbId needs to be RSA-encrypted too (based on the curl example)
        $encryptedDbId = $this->rsaEncryptPassword($dbId, $publicKeyXml);

        $loginPayload = json_encode([
            'AssymetricKeyId' => (string) $keyId,
            'Email' => $username,
            'Senha' => $encryptedPassword,
            'ip' => $ip,
            'Mobile' => 0,
            'dbId' => $encryptedDbId ?: $dbId,
        ]);

        $loginResponse = $this->curlPost(
            self::API_BASE . '/api/LoginUnificado/loginV3',
            $loginPayload,
            $cookieJar,
            [
                'Content-Type: application/json;charset=UTF-8',
                'Accept: application/json, text/plain, */*',
                'Referer: ' . self::API_BASE . '/Login_Unificado/loginUnificado.html',
            ],
        );

        if (!$loginResponse['body']) {
            return ['success' => false, 'error' => 'No response from loginV3 (HTTP ' . $loginResponse['status'] . ')'];
        }

        if ($loginResponse['status'] === 401) {
            return ['success' => false, 'error' => 'Invalid credentials (HTTP 401)'];
        }

        if ($loginResponse['status'] === 400) {
            $errorData = json_decode($loginResponse['body'], true);
            $errorMsg = $errorData['Message'] ?? $loginResponse['body'];

            // MEDX returns HTTP 400 with "já logado ... token <TOKEN>" when user already has an active session.
            // Extract and reuse the existing token.
            if (preg_match('/token\s+(\S+)/', $errorMsg, $tokenMatch)) {
                $existingToken = trim($tokenMatch[1]);

                $this->log->info(
                    "MedxWebHealthChecker: User already logged in, reusing existing token."
                );

                return [
                    'success' => true,
                    'bearerToken' => $existingToken,
                    'sessionCookies' => '',
                ];
            }

            return ['success' => false, 'error' => 'Login failed: ' . $errorMsg];
        }

        if ($loginResponse['status'] !== 200) {
            return ['success' => false, 'error' => 'Unexpected loginV3 status: HTTP ' . $loginResponse['status'] . ' - ' . $loginResponse['body']];
        }

        // The response body is the Bearer token (a JSON string)
        $token = json_decode($loginResponse['body']);

        if (!$token || !is_string($token)) {
            // The token might be returned as a plain string without quotes
            $token = trim($loginResponse['body'], " \t\n\r\0\x0B\"");
        }

        if (!$token) {
            return ['success' => false, 'error' => 'No token in loginV3 response'];
        }

        // Extract cookies from jar file
        $sessionCookies = '';
        $jarContent = file_get_contents($cookieJar);

        if ($jarContent) {
            $cookies = [];

            foreach (explode("\n", $jarContent) as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = explode("\t", $line);

                if (count($parts) >= 7) {
                    $cookies[] = $parts[5] . '=' . $parts[6];
                }
            }

            $sessionCookies = implode('; ', $cookies);
        }

        return [
            'success' => true,
            'bearerToken' => $token,
            'sessionCookies' => $sessionCookies,
        ];
    }

    /**
     * Parse SoftwareId from VerificaEmailCripto response.
     *
     * Possible response formats:
     * - "Ok:Success:SoftwareId:12345"
     * - "Ok:JSON:[{\"SoftwareId\":\"12345\",\"Nome\":\"Clinic\"}]"
     * - Error messages starting with "Erro:"
     */
    private function parseSoftwareId(string $response): ?string
    {
        // Remove surrounding quotes if JSON-encoded string
        $response = trim($response, " \t\n\r\0\x0B\"");

        if (str_contains($response, 'Erro:')) {
            $this->log->warning("MedxWebHealthChecker: VerificaEmailCripto error: {$response}");

            return null;
        }

        // Format: "Ok:Success:SoftwareId:12345"
        if (preg_match('/Success:SoftwareId:(.+)$/', $response, $m)) {
            return trim($m[1]);
        }

        // Format: "Ok:JSON:[{...}]" - multiple clinics, take the first one
        if (str_contains($response, 'JSON:')) {
            $jsonPart = substr($response, strpos($response, 'JSON:') + 5);
            $clinics = json_decode($jsonPart, true);

            if (is_array($clinics) && count($clinics) > 0) {
                return (string) ($clinics[0]['SoftwareId'] ?? '');
            }
        }

        return null;
    }

    /**
     * RSA-encrypt a string using an XML public key with OAEP SHA-1 padding.
     *
     * The MEDX frontend uses System.Security.Cryptography.RSACryptoServiceProvider.Encrypt(data, true)
     * which corresponds to OAEP with SHA-1 padding.
     */
    private function rsaEncryptPassword(string $plaintext, string $publicKeyXml): ?string
    {
        // Parse the XML public key to extract Modulus and Exponent
        $modulus = null;
        $exponent = null;

        if (preg_match('/<Modulus>(.*?)<\/Modulus>/s', $publicKeyXml, $m)) {
            $modulus = base64_decode($m[1]);
        }

        if (preg_match('/<Exponent>(.*?)<\/Exponent>/s', $publicKeyXml, $m)) {
            $exponent = base64_decode($m[1]);
        }

        if (!$modulus || !$exponent) {
            $this->log->error('MedxWebHealthChecker: Could not parse RSA public key XML');

            return null;
        }

        // Build a PEM public key from modulus and exponent
        $pemKey = $this->buildPemFromModulusExponent($modulus, $exponent);

        if (!$pemKey) {
            $this->log->error('MedxWebHealthChecker: Could not build PEM key');

            return null;
        }

        $publicKey = openssl_pkey_get_public($pemKey);

        if (!$publicKey) {
            $this->log->error('MedxWebHealthChecker: openssl_pkey_get_public failed: ' . openssl_error_string());

            return null;
        }

        $encrypted = '';
        $result = openssl_public_encrypt($plaintext, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);

        if (!$result) {
            $this->log->error('MedxWebHealthChecker: openssl_public_encrypt failed: ' . openssl_error_string());

            return null;
        }

        return base64_encode($encrypted);
    }

    /**
     * Build a PEM-encoded RSA public key from raw modulus and exponent bytes.
     */
    private function buildPemFromModulusExponent(string $modulus, string $exponent): ?string
    {
        // Ensure modulus has a leading zero byte if the high bit is set (positive integer)
        if (ord($modulus[0]) > 0x7f) {
            $modulus = "\x00" . $modulus;
        }

        // Ensure exponent has a leading zero byte if the high bit is set
        if (ord($exponent[0]) > 0x7f) {
            $exponent = "\x00" . $exponent;
        }

        // Build ASN.1 DER structure for RSA public key
        $modulusAsn = $this->asn1Integer($modulus);
        $exponentAsn = $this->asn1Integer($exponent);

        // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
        $rsaPublicKey = $this->asn1Sequence($modulusAsn . $exponentAsn);

        // Wrap in BIT STRING
        $bitString = $this->asn1BitString($rsaPublicKey);

        // Algorithm identifier for RSA: OID 1.2.840.113549.1.1.1 + NULL
        $algorithmIdentifier = $this->asn1Sequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"
        );

        // SubjectPublicKeyInfo
        $subjectPublicKeyInfo = $this->asn1Sequence($algorithmIdentifier . $bitString);

        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        if ($length < 0x100) {
            return "\x81" . chr($length);
        }

        if ($length < 0x10000) {
            return "\x82" . pack('n', $length);
        }

        return "\x83" . chr(($length >> 16) & 0xFF) . pack('n', $length & 0xFFFF);
    }

    private function asn1Integer(string $bytes): string
    {
        return "\x02" . $this->asn1Length(strlen($bytes)) . $bytes;
    }

    private function asn1Sequence(string $data): string
    {
        return "\x30" . $this->asn1Length(strlen($data)) . $data;
    }

    private function asn1BitString(string $data): string
    {
        // Prepend a 0x00 byte for "unused bits" count
        $data = "\x00" . $data;

        return "\x03" . $this->asn1Length(strlen($data)) . $data;
    }

    /**
     * Get external IP address (best-effort).
     */
    private function getExternalIp(): string
    {
        $ch = curl_init('https://docflixapi.azurewebsites.net/api/ip/getip');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200 && $result) {
            $ip = json_decode($result);

            if (is_string($ip)) {
                return $ip;
            }

            // May be returned as plain string
            return trim($result, " \t\n\r\0\x0B\"");
        }

        return '';
    }

    private function curlGet(string $url, string $cookieJar, array $headers = []): string
    {
        $ch = curl_init($url);

        $defaultHeaders = [
            'Accept: application/json, text/plain, */*',
            'Cache-Control: no-cache',
            'Referer: ' . self::API_BASE . '/Login_Unificado/loginUnificado.html',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        ]);

        $result = curl_exec($ch);
        curl_close($ch);

        return is_string($result) ? $result : '';
    }

    /**
     * @return array{body: string, status: int}
     */
    private function curlPost(
        string $url,
        string $postFields,
        string $cookieJar,
        array $headers = [],
    ): array {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_FOLLOWLOCATION => false,
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
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'body' => is_string($result) ? $result : '',
            'status' => $status,
        ];
    }

    private function updateTokenAndCookies(Entity $credential, string $newToken, string $newCookies): void
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

        $config['bearerToken'] = $newToken;
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
                "MedxWebHealthChecker: Updated bearer token and session cookies for credential '{$credentialId}'."
            );
        } catch (\Throwable $e) {
            $this->log->error(
                "MedxWebHealthChecker: Failed to persist token for '{$credentialId}': " .
                $e->getMessage()
            );
        }
    }

    private function elapsedMs(int $startHrtime): int
    {
        return (int) ((hrtime(true) - $startHrtime) / 1_000_000);
    }
}
