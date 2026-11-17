<?php

namespace Espo\Modules\FeatureSelfService;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Modules\FeatureSelfService\Tools\Plans\SeatSelectFactory;

class Binding implements BindingProcessor
{
    public function process(Binder $binder): void
    {
        $binder->bindImplementation(\Espo\Core\Select\Applier\Factory::class, SeatSelectFactory::class);
    }
}
