<?php

namespace Espo\Modules\Global\Hooks\Appointment;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;

class SyncAssignedUsers implements BeforeSave
{
    public function beforeSave(Entity $entity, \Espo\ORM\Repository\Option\SaveOptions $options): void
    {
        // 1. Determine the final state of Location and Professionals
        
        // Use 'has' to see if it's in the save request, otherwise use fetched value
        // This ensures that setting locationId to null (clearing it) is respected
        $locationId = $entity->has('locationId') ? 
            $entity->get('locationId') : 
            $entity->getFetched('locationId');
            
        $professionalsIds = $entity->has('professionalsIds') ? 
            $entity->get('professionalsIds') : 
            ($entity->getFetched('professionalsIds') ?: []);

        if (!is_array($professionalsIds)) {
            $professionalsIds = [];
        }

        // 2. Re-calculate 'assignedUsersIds' (Professionals + Location)
        // This ALWAYS overrides whatever was passed as 'assignedUsersIds' in the request
        $assignedUsersIds = $professionalsIds;
        
        if (is_string($locationId) && !empty($locationId)) {
            $assignedUsersIds[] = $locationId;
        }
        
        $assignedUsersIds = array_values(array_unique(array_filter($assignedUsersIds, 'is_string')));

        // Apply the synchronized set to assignedUsersIds
        $entity->set('assignedUsersIds', $assignedUsersIds);
    }
}
