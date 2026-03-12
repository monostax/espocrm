<?php

namespace Espo\Modules\FeatureClinica\Hooks\Jornada;

use Espo\ORM\Entity;

class SetNome
{
    public function beforeSave(Entity $entity, array $options = []): void
    {
        if ($entity->isFieldChanged('nome')) {
            $entity->set('name', $entity->get('nome'));
        }
        
        if (!$entity->get('name') && $entity->get('nome')) {
             $entity->set('name', $entity->get('nome'));
        }
    }
}
