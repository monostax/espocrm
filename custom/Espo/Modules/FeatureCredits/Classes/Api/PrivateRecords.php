<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\Api;

use Espo\Core\Exceptions\Forbidden;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Reject generic financial record queries before executing filters or revealing counts. */
final class PrivateRecords implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw new Forbidden('Use the credit workspace to read accounting records.');
    }
}
