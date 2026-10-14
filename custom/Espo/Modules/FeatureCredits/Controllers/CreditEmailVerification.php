<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Modules\FeatureCredits\Verification\Kind;

final class CreditEmailVerification extends CreditPhoneVerification
{
    protected const KIND = Kind::EMAIL;
}
