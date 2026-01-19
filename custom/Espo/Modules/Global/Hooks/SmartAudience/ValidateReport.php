<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Hooks\SmartAudience;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Advanced\Entities\Report;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Validates that the selected Report is of type 'List' and matches the entityType.
 *
 * @implements BeforeSave<Entity>
 */
class ValidateReport implements BeforeSave
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $reportId = $entity->get('reportId');

        if (!$reportId) {
            return;
        }

        // Only validate if report has changed
        if (!$entity->isNew() && !$entity->isAttributeChanged('reportId')) {
            return;
        }

        /** @var ?Report $report */
        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $reportId);

        if (!$report) {
            throw new BadRequest('Selected report does not exist.');
        }

        // Validate report type is List
        if ($report->getType() !== Report::TYPE_LIST) {
            throw new BadRequest(
                'Only List type reports are supported for Smart Audiences. ' .
                'The selected report is of type "' . $report->getType() . '".'
            );
        }

        // Validate report entityType matches SmartAudience entityType
        $smartAudienceEntityType = $entity->get('entityType');
        $reportEntityType = $report->getTargetEntityType();

        if ($smartAudienceEntityType && $reportEntityType !== $smartAudienceEntityType) {
            throw new BadRequest(
                'Report entity type mismatch. ' .
                'Smart Audience targets "' . $smartAudienceEntityType . '" ' .
                'but the selected report targets "' . $reportEntityType . '".'
            );
        }

        // If SmartAudience entityType is not set, set it from the report
        if (!$smartAudienceEntityType && $reportEntityType) {
            $entity->set('entityType', $reportEntityType);
        }
    }
}
