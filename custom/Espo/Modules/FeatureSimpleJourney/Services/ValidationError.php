<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error\Body;
use Espo\Core\Exceptions\Forbidden;

class ValidationError
{
    public static function badRequest(string $key, string $reason): BadRequest
    {
        return BadRequest::createWithBody($reason, Body::create()->withMessageTranslation($key, 'SimpleJourney'));
    }

    public static function forbidden(): Forbidden
    {
        return Forbidden::createWithBody(
            'The selected record is not accessible.',
            Body::create()->withMessageTranslation('notAccessible', 'SimpleJourney'),
        );
    }
}
