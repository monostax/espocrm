<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeatureClinica\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Log;

/**
 * Validates that all consuming entities have matching entityList
 * for their procedimento linkParent field, based on the procedure
 * type registry in procedureTypes.json.
 */
class ValidateProcedureTypeRegistry implements RebuildAction
{
    public function __construct(
        private Metadata $metadata,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureClinica Module: Validating procedure type registry...');

        $registeredEntityList = $this->metadata->get(['app', 'procedureTypes', 'entityList']) ?? [];
        $consumingEntities = $this->metadata->get(['app', 'procedureTypes', 'consumingEntities']) ?? [];

        $errorCount = 0;

        foreach ($consumingEntities as $entityType) {
            $fieldEntityList = $this->metadata->get(
                ['entityDefs', $entityType, 'fields', 'procedimento', 'entityList']
            ) ?? [];

            $missing = array_diff($registeredEntityList, $fieldEntityList);
            $extra = array_diff($fieldEntityList, $registeredEntityList);

            if (!empty($missing)) {
                $missingStr = implode(', ', $missing);
                $this->log->warning(
                    "FeatureClinica Module: {$entityType}.procedimento.entityList is missing: {$missingStr}"
                );
                $errorCount++;
            }

            if (!empty($extra)) {
                $extraStr = implode(', ', $extra);
                $this->log->warning(
                    "FeatureClinica Module: {$entityType}.procedimento.entityList has extra entries: {$extraStr}"
                );
                $errorCount++;
            }
        }

        if ($errorCount === 0) {
            $this->log->info('FeatureClinica Module: Procedure type registry validation passed.');
        } else {
            $this->log->warning(
                "FeatureClinica Module: Procedure type registry validation found {$errorCount} issue(s)."
            );
        }
    }
}
