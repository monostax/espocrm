<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Runs after Espo's controller handler has applied its default cache headers. */
class PrivateResponse implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('Cache-Control', 'private, no-store');
    }
}
