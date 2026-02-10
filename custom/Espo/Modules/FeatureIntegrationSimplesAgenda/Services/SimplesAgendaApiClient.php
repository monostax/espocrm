<?php

namespace Espo\Modules\FeatureIntegrationSimplesAgenda\Services;

use Espo\Core\Exceptions\Error;

/**
 * API client for SimplesAgenda - handles login and client export.
 */
class SimplesAgendaApiClient
{
    private const DEFAULT_TIMEOUT = 60;
    private const CONNECT_TIMEOUT = 15;
    private const BASE_URL = 'https://www.simplesagenda.com.br';

    public function __construct() {}

    /**
     * Perform login via crud.php (acao=autentica_usuario) and return path to cookie file.
     *
     * @param string $username Email/login
     * @param string $password Password
     * @param string $usernameField Form field name for username (default: login)
     * @param string $passwordField Form field name for password (default: senha)
     * @param string|null $empresa Optional empresa (company) ID if required
     * @return string Path to temp cookie file - caller must unlink when done
     * @throws Error
     */
    public function login(
        string $username,
        string $password,
        string $usernameField = 'login',
        string $passwordField = 'senha',
        ?string $empresa = null
    ): string {
        $cookieFile = tempnam(sys_get_temp_dir(), 'sa_cookies_');
        if ($cookieFile === false) {
            throw new Error('Failed to create temp cookie file');
        }

        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36';
        $baseHeaders = [
            'User-Agent: ' . $userAgent,
            'Accept: application/xml, text/xml, */*; q=0.01',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        ];

        // Step 1: GET login page to establish session (PHPSESSID)
        $getCh = curl_init(self::BASE_URL . '/autenticacao_usuario.php');
        if ($getCh === false) {
            @unlink($cookieFile);
            throw new Error("Could not initialize cURL for session");
        }
        curl_setopt_array($getCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => self::DEFAULT_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => $baseHeaders,
        ]);
        $getResult = curl_exec($getCh);
        curl_close($getCh);
        if ($getResult === false) {
            @unlink($cookieFile);
            throw new Error("Failed to establish session");
        }

        // Step 2: POST login credentials to crud.php
        $url = self::BASE_URL . '/crud.php';
        $postParams = [
            'acao' => 'autentica_usuario',
            $usernameField => $username,
            $passwordField => $password,
            'conectado' => '1',
        ];
        if ($empresa !== null && $empresa !== '') {
            $postParams['empresa'] = $empresa;
        }
        $postData = http_build_query($postParams);

        $ch = curl_init($url);
        if ($ch === false) {
            @unlink($cookieFile);
            throw new Error("Could not initialize cURL for login");
        }

        $headers = array_merge($baseHeaders, [
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Origin: ' . self::BASE_URL,
            'Referer: ' . self::BASE_URL . '/autenticacao_usuario.php',
            'X-Requested-With: XMLHttpRequest',
        ]);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => self::DEFAULT_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            @unlink($cookieFile);
            throw new Error("Login cURL error: {$curlError}");
        }

        if ($httpCode >= 400) {
            @unlink($cookieFile);
            throw new Error("Login failed: HTTP {$httpCode}");
        }

        $loginResponse = trim($result);
        if ($loginResponse !== '' && ($loginResponse[0] === '{' || $loginResponse[0] === '[')) {
            $json = json_decode($loginResponse, true);
            if (isset($json['sucesso']) && $json['sucesso'] === false) {
                $msg = $json['mensagem'] ?? $json['msg'] ?? $json['message'] ?? 'Login failed';
                @unlink($cookieFile);
                throw new Error("SimplesAgenda login failed: {$msg}");
            }
        }

        return $cookieFile;
    }

    /**
     * Export clients as XLS binary content.
     *
     * @param string $cookieFile Path to cookie file from login()
     * @return string Raw XLS binary content
     * @throws Error
     */
    public function exportClientes(string $cookieFile): string
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36';
        $baseHeaders = [
            'User-Agent: ' . $userAgent,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
        ];

        // Step 1: GET cliente.php to enter the client section (required before export works)
        $clienteCh = curl_init(self::BASE_URL . '/cliente.php');
        if ($clienteCh !== false) {
            curl_setopt_array($clienteCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_COOKIEJAR => $cookieFile,
                CURLOPT_COOKIEFILE => $cookieFile,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => self::DEFAULT_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_HTTPHEADER => $baseHeaders,
            ]);
            curl_exec($clienteCh);
            curl_close($clienteCh);
        }

        // Step 2: POST export request
        $url = self::BASE_URL . '/crud.php';
        $postData = 'acao=exporta_clientes';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new Error("Could not initialize cURL for export");
        }

        $headers = [
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Accept: application/xml, text/xml, */*; q=0.01',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
            'Origin: ' . self::BASE_URL,
            'Referer: ' . self::BASE_URL . '/cliente.php',
            'X-Requested-With: XMLHttpRequest',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => self::DEFAULT_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            throw new Error("Export cURL error: {$curlError}");
        }

        if ($httpCode >= 400) {
            throw new Error("Export failed: HTTP {$httpCode}");
        }

        if (empty($result)) {
            throw new Error("Export returned empty response");
        }

        $trimmed = trim($result);
        // SimplesAgenda returns XML with the XLS path, not the binary - fetch the file
        if (str_starts_with($trimmed, '<') && str_contains($trimmed, '<nome_excel>')) {
            $xlsPath = $this->extractXlsPathFromXml($result);
            if ($xlsPath !== null) {
                return $this->fetchXlsFile($cookieFile, $xlsPath);
            }
        }

        return $result;
    }

    /**
     * Extract the XLS file path from SimplesAgenda's XML response.
     */
    private function extractXlsPathFromXml(string $xml): ?string
    {
        $xml = @simplexml_load_string($xml);
        if ($xml === false) {
            return null;
        }
        $nomeExcel = $xml->nome_excel ?? null;
        if ($nomeExcel !== null) {
            return trim((string) $nomeExcel);
        }
        return null;
    }

    /**
     * Fetch the actual XLS file from the path returned by the export API.
     */
    private function fetchXlsFile(string $cookieFile, string $xlsPath): string
    {
        $url = self::BASE_URL . '/' . ltrim($xlsPath, '/');
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Error("Could not initialize cURL for XLS download");
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => self::DEFAULT_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
                'Accept: application/vnd.ms-excel,*/*',
                'Referer: ' . self::BASE_URL . '/cliente.php',
            ],
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            throw new Error("XLS download failed: {$curlError}");
        }

        if ($httpCode >= 400) {
            throw new Error("XLS download failed: HTTP {$httpCode}");
        }

        return $result;
    }
}
