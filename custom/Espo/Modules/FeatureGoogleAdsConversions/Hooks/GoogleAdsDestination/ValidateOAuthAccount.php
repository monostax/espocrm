<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Hooks\GoogleAdsDestination;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsDestination;
use Espo\Modules\FeatureGoogleAdsConversions\Rebuild\SeedOAuthProviderGoogleDataManager;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/** @implements BeforeSave<Entity> */
class ValidateOAuthAccount implements BeforeSave
{
    public static int $order = 15;

    public function __construct(
        private EntityManager $entityManager,
        private TenantResolver $tenantResolver,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof GoogleAdsDestination || $options->get('silent')) {
            return;
        }

        $destinationTenantId = trim((string) ($entity->get('tenantId') ?? ''));
        $accountId = trim((string) ($entity->get('oAuthAccountId') ?? ''));

        if ($destinationTenantId === '' || $accountId === '') {
            throw new BadRequest('Google Ads destination tenant and OAuth account are required.');
        }

        $account = $this->entityManager->getEntityById('OAuthAccount', $accountId);

        if (!$account instanceof CoreEntity) {
            throw new BadRequest('The selected Google OAuth account does not exist.');
        }

        $providerId = trim((string) ($account->get('providerId') ?? ''));
        $provider = $providerId !== ''
            ? $this->entityManager->getEntityById('OAuthProvider', $providerId)
            : null;

        if (
            !$provider ||
            $provider->get('provider') !== SeedOAuthProviderGoogleDataManager::PROVIDER_DISCRIMINATOR
        ) {
            throw new BadRequest('The selected OAuth account must use the Google Data Manager provider.');
        }

        try {
            $teamIds = array_values($account->getLinkMultipleIdList('teams'));
        } catch (Throwable) {
            $teamIds = [];
        }

        $tenantIds = $this->tenantResolver->resolveAllFromTeamIds($teamIds);

        if (count($tenantIds) !== 1 || $tenantIds[0] !== $destinationTenantId) {
            throw new BadRequest(
                'The selected OAuth account must resolve to exactly the same tenant as the Google Ads destination.'
            );
        }
    }
}
