<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\Account;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Global\Tools\BrazilianIdentifier;
use Espo\ORM\Entity;

class NormalizeCnpj
{
    public static int $order = 8;

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew() && !$entity->isAttributeChanged('cnpj')) return;
        $value = $entity->get('cnpj');
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $entity->set('cnpj', null);
            return;
        }
        $normalized = BrazilianIdentifier::cnpj($value);
        if ($normalized === null) throw new BadRequest('Invalid CNPJ.');
        $entity->set('cnpj', $normalized);
    }
}
