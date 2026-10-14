<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

class BounceBanCredentials
{
    public function get(): array
    {
        $key = getenv('VERIFICATION_BOUNCEBAN_API_KEY');
        if (!is_string($key) || trim($key) === '' || preg_match('/[\x00-\x1f\x7f]/', $key)) return [];
        return ['apiKey' => trim($key)];
    }
}
