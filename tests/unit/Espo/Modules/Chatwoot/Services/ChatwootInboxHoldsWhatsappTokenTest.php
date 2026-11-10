<?php

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Modules\Chatwoot\Services\ChatwootInboxIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Chatwoot hides the WhatsApp api_key and returns its SHA-256 fingerprint; older
 * Chatwoot builds still return the raw key. The CRM must recognise its own token
 * either way, and must never treat a missing key as a match.
 */
class ChatwootInboxHoldsWhatsappTokenTest extends TestCase
{
    private function holds(array $inbox, string $token): bool
    {
        return (new ReflectionMethod(ChatwootInboxIntegration::class, 'inboxHoldsWhatsappToken'))
            ->invoke(null, $inbox, $token);
    }

    public function testMatchesByFingerprint(): void
    {
        $inbox = ['whatsapp_api_key_fingerprint' => hash('sha256', 'meta-token'), 'provider_config' => []];

        $this->assertTrue($this->holds($inbox, 'meta-token'));
        $this->assertFalse($this->holds($inbox, 'other-token'));
    }

    public function testFingerprintWinsOverAStaleRawKey(): void
    {
        $inbox = [
            'whatsapp_api_key_fingerprint' => hash('sha256', 'new-token'),
            'provider_config' => ['api_key' => 'meta-token'],
        ];

        $this->assertFalse($this->holds($inbox, 'meta-token'));
    }

    public function testFallsBackToTheRawKeyFromOlderChatwoot(): void
    {
        $inbox = ['provider_config' => ['api_key' => 'meta-token']];

        $this->assertTrue($this->holds($inbox, 'meta-token'));
        $this->assertFalse($this->holds($inbox, 'other-token'));
    }

    public function testNoKeyInformationIsNeverAMatch(): void
    {
        $this->assertFalse($this->holds(['provider_config' => []], 'meta-token'));
        $this->assertFalse($this->holds(['whatsapp_api_key_fingerprint' => null, 'provider_config' => []], 'meta-token'));
    }
}
