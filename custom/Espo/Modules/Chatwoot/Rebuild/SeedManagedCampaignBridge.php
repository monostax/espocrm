<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\ORM\EntityManager;

/** Bind the deployment credential to the existing seeded platform, never to request input. */
class SeedManagedCampaignBridge implements RebuildAction
{
    public function __construct(private EntityManager $em, private Config $config, private ConfigWriter $writer) {}

    public function process(): void
    {
        $key = (string) getenv('CRM_CAMPAIGN_KEY_ID');
        $secret = (string) getenv('CRM_CAMPAIGN_SIGNING_SECRET');
        $url = (string) getenv('CHATWOOT_CAMPAIGN_URL');
        if ($key === '' && $secret === '' && $url === '') return;
        if (!preg_match('/^[\w-]{1,100}$/D', $key) || strlen($secret) < 32 || !preg_match('#^https://[^/\s]+$#D', $url)) {
            throw new \RuntimeException('Managed campaign deployment configuration is incomplete.');
        }
        $platforms = iterator_to_array($this->em->getRDBRepository('ChatwootPlatform')->where([
            'isDefault' => true, 'frontendUrl' => (string) getenv('CHATWOOT_PLATFORM_FRONTEND_URL'),
        ])->limit(0, 2)->find(), false);
        if (count($platforms) !== 1) throw new \RuntimeException('Managed campaign default platform is unavailable or ambiguous.');
        $credentials = (array) $this->config->get('managedWhatsAppCampaignCredentials', []);
        // Re-key this deployment without retaining an earlier key ID that reads the same environment secret.
        foreach ($credentials as $oldKey => $entry) {
            $entry = (array) $entry;
            if (($entry['secretEnv'] ?? '') === 'CRM_CAMPAIGN_SIGNING_SECRET' &&
                ($entry['platformId'] ?? '') === $platforms[0]->getId()) unset($credentials[$oldKey]);
        }
        // Persist the environment variable name, not its secret value. All web and daemon pods receive it.
        $credentials[$key] = ['platformId' => $platforms[0]->getId(), 'secretEnv' => 'CRM_CAMPAIGN_SIGNING_SECRET', 'callbackUrl' => $url];
        $this->writer->set('managedWhatsAppCampaignCredentials', $credentials);
        $this->writer->save();
    }
}
