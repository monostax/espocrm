<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Core\Container\Loader;
use Espo\Core\InjectableFactory;

class SeatAclLoader implements Loader
{
    public function __construct(private InjectableFactory $injectableFactory) {}

    public function load(): SeatAclManager
    {
        return $this->injectableFactory->create(SeatAclManager::class);
    }
}
