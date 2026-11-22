<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\MetaGraphApiClient;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;
use RuntimeException;

/**
 * The phone code as Meta's authentication template (`otp.template`, copy-code button) from the Avisos number, sent
 * straight to Meta with the Avisos inbox's own integration (token, phone number id), not through Chatwoot: a code
 * must not sit in a conversation the Monostax team can read.
 */
class CodeWhatsApp
{
    private const TOKEN_TIMEOUT_SECONDS = 10;

    public function __construct(
        private EntityManager $em,
        private Settings $settings,
        private TokensProvider $tokens,
        private MetaGraphApiClient $graph,
    ) {}

    /**
     * Sends $code to $phone (E.164). Returns the number as WhatsApp knows it (Meta's wa_id), else $phone.
     *
     * @throws RuntimeException when Avisos has no usable integration or Meta refuses the message
     */
    public function send(string $phone, string $code): string
    {
        [$token, $phoneNumberId] = $this->credentials();
        $result = $this->graph->sendTemplateMessage($token, $phoneNumberId, $phone, $this->settings->codeTemplate(),
            $this->settings->codeLanguage(), [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
            ]);
        $waId = (string) ($result['contacts'][0]['wa_id'] ?? '');
        return preg_match('/^[1-9]\d{9,14}$/', $waId) ? '+' . $waId : $phone;
    }

    /** @return array{string, string} token and phone number id */
    private function credentials(): array
    {
        $inbox = $this->em->getEntityById('ChatwootInbox', $this->settings->inboxId());
        $integration = $inbox ? $this->em->getEntityById('ChatwootInboxIntegration', (string) $inbox->get('chatwootInboxIntegrationId')) : null;
        if (!$integration || $integration->get('status') !== 'ACTIVE' || !$integration->get('oAuthAccountId') || !$integration->get('phoneNumberId')) {
            throw new RuntimeException('The Avisos inbox has no active Meta integration.');
        }
        $token = $this->tokens->get((string) $integration->get('oAuthAccountId'), self::TOKEN_TIMEOUT_SECONDS)->getAccessToken();
        if ($token === '') throw new RuntimeException('The Avisos Meta token is empty.');
        return [$token, (string) $integration->get('phoneNumberId')];
    }
}
