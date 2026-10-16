<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

final class WhatsAppResult
{
    public readonly ?string $outcome;

    public function __construct(public readonly ?bool $registered)
    {
        $this->outcome = $registered === null ? null : ($registered ? 'registered' : 'not_registered');
    }
}
