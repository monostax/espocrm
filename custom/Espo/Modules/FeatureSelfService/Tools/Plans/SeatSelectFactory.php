<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Core\InjectableFactory;
use Espo\Core\Select\AccessControl\Applier;
use Espo\Core\Utils\Acl\UserAclManagerProvider;
use Espo\Entities\User;

class SeatSelectFactory extends \Espo\Core\Select\Applier\Factory
{
    public function __construct(InjectableFactory $injectableFactory, UserAclManagerProvider $userAclManagerProvider,
        private SeatBoundary $boundary)
    {
        parent::__construct($injectableFactory, $userAclManagerProvider);
    }

    public function createAccessControlFilter(string $entityType, User $user): Applier
    {
        return new SeatSelectAccess($entityType, $user, parent::createAccessControlFilter($entityType, $user), $this->boundary);
    }
}
