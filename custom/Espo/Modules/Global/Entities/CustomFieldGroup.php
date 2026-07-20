<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Entities;

use Espo\Core\ORM\Entity;

/**
 * Tenant-scoped group of custom fields for a target entity type.
 *
 * Groups are presentation structure (panels like "Address"). They do not
 * own value-key identity — that lives on CustomFieldDef.valueKey.
 */
class CustomFieldGroup extends Entity
{
    public const ENTITY_TYPE = 'CustomFieldGroup';
}
