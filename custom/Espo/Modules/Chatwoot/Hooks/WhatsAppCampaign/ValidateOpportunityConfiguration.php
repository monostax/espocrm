<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax - Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\WhatsAppCampaign;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\WhatsAppCampaignOpportunityService;
use Espo\ORM\Entity;

/**
 * Validates Opportunity routing and freezes it once a campaign leaves Draft.
 */
class ValidateOpportunityConfiguration
{
    public static int $order = 20;

    private const ATTRIBUTE_LIST = [
        'createOpportunity',
        'funnelId',
        'opportunityStageId',
        'opportunityAssignedUserId',
    ];

    public function __construct(
        private WhatsAppCampaignOpportunityService $opportunityService,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // The freeze applies to silent (system) saves as well: no lifecycle
        // path legitimately rewrites Opportunity routing after launch.
        $configurationChanged = $entity->isNew();

        foreach (self::ATTRIBUTE_LIST as $attribute) {
            $configurationChanged = $configurationChanged
                || $entity->isAttributeChanged($attribute);
        }

        if (!$configurationChanged) {
            return;
        }

        if (!$entity->isNew() && $entity->getFetched('status') !== 'Draft') {
            throw new BadRequest('Opportunity creation settings cannot be changed after the campaign starts.');
        }

        $this->opportunityService->assertConfiguration($entity);
    }
}
