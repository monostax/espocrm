<?php

namespace Espo\Modules\FeatureCredits;

use Espo\Core\Binding\{Binder, BindingProcessor, ContextualBinder};
use Espo\Core\Job\{PrepareProcessor, QueueUtil};
use Espo\Modules\FeatureCredits\Jobs\CreditQueueRecovery;

class Binding implements BindingProcessor
{
    public function process(Binder $binder): void
    {
        $binder->inContext(PrepareProcessor::class, static fn (ContextualBinder $b) =>
            $b->bindImplementation(QueueUtil::class, CreditQueueRecovery::class));
    }
}
