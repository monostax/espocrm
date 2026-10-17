<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import;

/** Only these categories cross the API/log boundary; never include upstream bodies. */
final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $category) { parent::__construct($category); }
}
