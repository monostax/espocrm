<?php

namespace Espo\Modules\FeatureCredential\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthProvider;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed/update OAuthProvider.provider enum values.
 */
class SeedProviderEnum implements RebuildAction
{
    private const PROVIDER_FIELD = 'provider';
    private const PROVIDER_OTHER = 'other';

    /**
     * @var array<string, string>
     */
    private const KNOWN_PROVIDER_BY_ID = [
        'msx_gmeet_01' => 'google-meet',
        'msx_google_cal_01' => 'google-calendar',
        'msx_meta_ig_01' => 'meta-instagram',
    ];

    /**
     * @var string[]
     */
    private const VALID_PROVIDERS = [
        'meta-whatsapp',
        'google-calendar',
        'google-meet',
        'meta-instagram',
        self::PROVIDER_OTHER,
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureCredential Module: Seeding OAuthProvider.provider values...');

        $providers = $this->entityManager
            ->getRDBRepository(OAuthProvider::ENTITY_TYPE)
            ->find();

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($providers as $provider) {
            $currentValue = $provider->get(self::PROVIDER_FIELD);
            $targetValue = $this->resolveProviderValue($provider->getId(), $currentValue);

            if ($targetValue === $currentValue) {
                $skippedCount++;
                continue;
            }

            $provider->set(self::PROVIDER_FIELD, $targetValue);
            $this->entityManager->saveEntity($provider);
            $updatedCount++;
        }

        $this->log->info(
            "FeatureCredential Module: OAuthProvider.provider seeding completed. Updated: {$updatedCount}, Skipped: {$skippedCount}"
        );
    }

    private function resolveProviderValue(string $providerId, mixed $currentValue): string
    {
        if (isset(self::KNOWN_PROVIDER_BY_ID[$providerId])) {
            return self::KNOWN_PROVIDER_BY_ID[$providerId];
        }

        if (is_string($currentValue) && in_array($currentValue, self::VALID_PROVIDERS, true)) {
            return $currentValue;
        }

        return self::PROVIDER_OTHER;
    }
}
