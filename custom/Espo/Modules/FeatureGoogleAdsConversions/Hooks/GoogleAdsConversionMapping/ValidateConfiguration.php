<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Hooks\GoogleAdsConversionMapping;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionMapping;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsDestination;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/** @implements BeforeSave<Entity> */
class ValidateConfiguration implements BeforeSave
{
    public static int $order = 10;

    private const CURRENCIES = ['USD', 'EUR', 'BRL', 'GBP', 'MXN', 'ARS'];

    public function __construct(
        private EntityManager $entityManager,
        private TenantResolver $tenantResolver,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof GoogleAdsConversionMapping || $options->get('silent')) {
            return;
        }

        $destination = $this->load(GoogleAdsDestination::ENTITY_TYPE, $entity->get('destinationId'));
        $funnel = $this->load('Funnel', $entity->get('funnelId'));
        $stage = $this->load('OpportunityStage', $entity->get('opportunityStageId'));

        if (!$destination instanceof GoogleAdsDestination || !$funnel || !$stage) {
            throw new BadRequest('Destination, funnel, and opportunity stage must all exist.');
        }

        if ((string) $stage->get('funnelId') !== (string) $funnel->getId()) {
            throw new BadRequest('The selected opportunity stage does not belong to the selected funnel.');
        }

        $mappingTenantId = trim((string) ($entity->get('tenantId') ?? ''));
        $destinationTenantId = trim((string) ($destination->get('tenantId') ?? ''));
        $funnelTenantId = trim((string) ($funnel->get('tenantId') ?? ''));
        $stageTenantId = $this->resolveTenant($stage);

        if (
            $mappingTenantId === '' ||
            $destinationTenantId === '' ||
            $funnelTenantId === '' ||
            $stageTenantId === '' ||
            count(array_unique([
                $mappingTenantId,
                $destinationTenantId,
                $funnelTenantId,
                $stageTenantId,
            ])) !== 1
        ) {
            throw new BadRequest('Mapping, destination, funnel, and stage tenants must be set and equal.');
        }

        $actionId = trim((string) ($entity->get('conversionActionId') ?? ''));

        if ($actionId === '' || !ctype_digit($actionId)) {
            throw new BadRequest('Google Ads conversion action ID must be numeric.');
        }

        $lookbackDays = (int) ($entity->get('lookbackDays') ?? 0);

        if ($lookbackDays < 1 || $lookbackDays > 90) {
            throw new BadRequest('Attribution lookback must be between 1 and 90 days.');
        }

        if ($entity->get('valueSource') === GoogleAdsConversionMapping::VALUE_SOURCE_FIXED) {
            $value = $entity->get('fixedValue');

            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
                throw new BadRequest('A non-negative fixed value is required when value source is Fixed.');
            }
        }

        if ($entity->get('currencySource') === GoogleAdsConversionMapping::CURRENCY_SOURCE_FIXED) {
            $currency = (string) ($entity->get('fixedCurrency') ?? '');

            if (!in_array($currency, self::CURRENCIES, true)) {
                throw new BadRequest('A supported fixed currency is required when currency source is Fixed.');
            }
        }
    }

    private function load(string $entityType, mixed $id): ?Entity
    {
        return is_string($id) && $id !== ''
            ? $this->entityManager->getEntityById($entityType, $id)
            : null;
    }

    private function resolveTenant(Entity $entity): string
    {
        $ownTenantId = trim((string) ($entity->get('tenantId') ?? ''));

        if ($ownTenantId !== '') {
            return $ownTenantId;
        }

        if (!$entity instanceof CoreEntity || !$entity->hasLinkMultipleField('teams')) {
            return '';
        }

        try {
            $tenantIds = $this->tenantResolver->resolveAllFromTeamIds(
                array_values($entity->getLinkMultipleIdList('teams')),
            );
        } catch (Throwable) {
            return '';
        }

        return count($tenantIds) === 1 ? $tenantIds[0] : '';
    }
}
