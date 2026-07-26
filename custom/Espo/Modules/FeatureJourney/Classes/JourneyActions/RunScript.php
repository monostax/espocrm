<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;

class RunScript implements Action
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private TenantGuard $tenantGuard,
    ) {}

    public function run(ActionContext $context): void
    {
        $className = (string) ($context->params['className'] ?? '');

        $this->tenantGuard->assertClassAllowed($className, 'scriptClassNameList');

        $impl = $this->injectableFactory->create($className);

        if ($impl instanceof Action) {
            $impl->run($context);

            return;
        }

        if (is_callable($impl)) {
            $impl($context);

            return;
        }

        if (method_exists($impl, 'run')) {
            $impl->run($context);

            return;
        }

        throw new Error("RunScript: class '{$className}' is not runnable.");
    }
}
