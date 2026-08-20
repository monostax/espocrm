<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Hooks\GoogleAdsConversionUpload;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionMapping;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionUpload;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsDestination;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Entity> */
class ValidateTenantRelationships implements BeforeSave
{
    public static int $order = 10;

    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof GoogleAdsConversionUpload || !$entity->isNew()) {
            return;
        }

        $destination = $this->load(
            GoogleAdsDestination::ENTITY_TYPE,
            $entity->get('destinationId'),
        );
        $mapping = $this->load(
            GoogleAdsConversionMapping::ENTITY_TYPE,
            $entity->get('mappingId'),
        );
        $sourceType = $entity->get('subjectType');
        $source = is_string($sourceType) && in_array($sourceType, ['Opportunity', 'Contact'], true)
            ? $this->load($sourceType, $entity->get('subjectId'))
            : null;

        if (
            !$destination instanceof GoogleAdsDestination ||
            !$mapping instanceof GoogleAdsConversionMapping ||
            !$source
        ) {
            throw new BadRequest('Upload destination, mapping, and source must exist.');
        }

        $tenantId = trim((string) ($entity->get('tenantId') ?? ''));

        if (
            $tenantId === '' ||
            $tenantId !== (string) $destination->get('tenantId') ||
            $tenantId !== (string) $mapping->get('tenantId') ||
            $tenantId !== (string) $source->get('tenantId') ||
            (string) $mapping->get('destinationId') !== $destination->getId()
        ) {
            throw new BadRequest('Upload destination, mapping, and source tenants must be set and equal.');
        }

        if (
            $sourceType === 'Opportunity' &&
            (
                (string) $entity->get('opportunityId') !== $source->getId() ||
                (string) $mapping->get('funnelId') !== (string) $source->get('funnelId') ||
                (string) $mapping->get('opportunityStageId') !== (string) $entity->get('stageToId') ||
                (string) $source->get('opportunityStageId') !== (string) $entity->get('stageToId')
            )
        ) {
            throw new BadRequest('Upload source does not match the mapped Opportunity stage.');
        }

        if ($sourceType === 'Contact' && (string) $entity->get('contactId') !== $source->getId()) {
            throw new BadRequest('Upload source does not match the linked Contact.');
        }

        $contactId = $entity->get('contactId');

        if (is_string($contactId) && $contactId !== '') {
            $contact = $this->load('Contact', $contactId);

            if (!$contact || (string) $contact->get('tenantId') !== $tenantId) {
                throw new BadRequest('Upload Contact must belong to the upload tenant.');
            }
        }
    }

    private function load(string $entityType, mixed $id): ?Entity
    {
        return is_string($id) && $id !== ''
            ? $this->entityManager->getEntityById($entityType, $id)
            : null;
    }
}
