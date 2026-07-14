<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureOAuthEnhanced;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth\GenericProviderFactory;
use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth\TokenSetter;

/**
 * Binding configuration for the FeatureOAuthEnhanced module.
 */
class Binding implements BindingProcessor
{
    public function process(Binder $binder): void
    {
        $binder->bindImplementation(
            \Espo\Tools\OAuth\TokenSetter::class,
            TokenSetter::class
        );

        $binder->bindImplementation(
            \Espo\Tools\OAuth\GenericProviderFactory::class,
            GenericProviderFactory::class
        );
    }
}
