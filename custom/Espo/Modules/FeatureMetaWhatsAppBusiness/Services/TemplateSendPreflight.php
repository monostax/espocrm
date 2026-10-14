<?php

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateParameters;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;

/** One live lookup and refresh per launch/action, before any recipient send. */
class TemplateSendPreflight
{
    public function __construct(
        private EntityManager $entityManager,
        private TokensProvider $tokensProvider,
        private CredentialResolver $credentialResolver,
        private MetaGraphApiClient $meta,
        private ChatwootApiClient $chatwoot,
        private Log $log,
    ) {}

    public function check(
        Entity $inbox, string $url, string $key, int $accountId, string $name, string $language,
        mixed $body, mixed $header, ?string $mediaUrl, ?string $mediaType,
    ): array {
        $deadline = new TemplateDeadline(30);
        $start = hrtime(true);
        $outcome = 'failed';
        try {
            [$token, $waba] = $this->credentials($inbox, $deadline);
            if ($name === '' || $language === '') {
                throw new Error('An exact template name and language are required.');
            }
            $template = $this->meta->getExactTemplate($token, $waba, $name, $language, $deadline);
            if (!$template || ($template['status'] ?? '') !== 'APPROVED') {
                throw new Error('The selected name/language is missing or is not APPROVED at Meta.');
            }
            TemplateParameters::validate($template, $body, $header, $mediaUrl, $mediaType);
            $inboxId = (int) $inbox->get('chatwootInboxId');
            $this->chatwoot->refreshTemplateCatalog($url, $key, $accountId, $inboxId, $deadline);
            $this->awaitReady($template, $url, $key, $accountId, $inboxId, $deadline);
            $outcome = 'ready';
            return $template;
        } finally {
            $this->log->info('WhatsApp template preflight', [
                'inboxId' => $inbox->getId(), 'outcome' => $outcome,
                'durationMs' => (int) ((hrtime(true) - $start) / 1e6),
            ]);
        }
    }

    public function awaitReady(array $live, string $url, string $key, int $account, int $inbox, TemplateDeadline $deadline): void
    {
        $signature = TemplateParameters::signature($live);
        while ($deadline->remaining() > 0) {
            $templates = $this->chatwoot->readTemplateCatalog($url, $key, $account, $inbox, $deadline);
            $deadline->milliseconds();
            foreach ($templates as $cached) {
                if (TemplateParameters::signature($cached) === $signature) {
                    return;
                }
            }
            if ($deadline->remaining() <= 2) {
                break;
            }
            $this->waitBetweenChecks();
        }
        throw new Error('Chatwoot template catalog is not ready within the 30-second preflight budget. No messages were sent.');
    }

    protected function waitBetweenChecks(): void
    {
        usleep(2000000);
    }

    /** Sender authorization is enforced by the campaign/journey entry point, not virtual management ACL. */
    private function credentials(Entity $inbox, TemplateDeadline $deadline): array
    {
        $integration = $this->entityManager->getEntityById('ChatwootInboxIntegration',
            (string) $inbox->get('chatwootInboxIntegrationId'));
        if (!$integration || $integration->get('status') !== 'ACTIVE') {
            throw new Error('An active Meta inbox integration is required for template preflight.');
        }
        $waba = $integration->get('businessAccountId');
        $oauthId = $integration->get('oAuthAccountId');
        $credentialId = $integration->get('credentialId');
        try {
            if ($oauthId) {
                $token = $this->tokensProvider->get($oauthId, $deadline->milliseconds() / 1000)->getAccessToken();
            } elseif ($credentialId) {
                $config = $this->credentialResolver->resolve($credentialId, $deadline->milliseconds() / 1000);
                if ($waba && $waba !== ($config->businessAccountId ?? null)) {
                    throw new Error('Inbox and Credential WABA do not match.');
                }
                $waba = $config->businessAccountId ?? null;
                $token = $config->accessToken ?? null;
            }
        } catch (\Throwable) {
            throw new Error('Could not resolve Meta credentials for template preflight.');
        }
        if (empty($token) || !$waba) {
            throw new Error('Meta token and WABA are required for template preflight.');
        }
        return [$token, $waba];
    }
}
