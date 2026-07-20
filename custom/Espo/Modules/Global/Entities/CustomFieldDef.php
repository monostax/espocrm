<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Entities;

use Espo\Core\ORM\Entity;

/**
 * Tenant-scoped custom field definition for a target entity type.
 *
 * Values are stored on the host record's `customFields` jsonObject under
 * the immutable `valueKey` (e.g. "address.city" or bare "plan").
 */
class CustomFieldDef extends Entity
{
    public const ENTITY_TYPE = 'CustomFieldDef';

    public const TYPE_VARCHAR = 'varchar';
    public const TYPE_TEXT = 'text';
    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';
    public const TYPE_BOOL = 'bool';
    public const TYPE_DATE = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_ENUM = 'enum';
    public const TYPE_MULTI_ENUM = 'multiEnum';
}
