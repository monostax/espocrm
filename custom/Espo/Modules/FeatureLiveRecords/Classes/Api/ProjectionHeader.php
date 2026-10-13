<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Classes\Api;

use Espo\Modules\FeatureLiveRecords\Subscription\Projection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Advertise only replayable reads, with a baseline from the actual authorized response. */
final class ProjectionHeader implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $path = preg_replace('~^/api/v1~', '', $request->getUri()->getPath());
        if ($request->getMethod() !== 'GET' || $response->getStatusCode() !== 200 || !Projection::accepts($path)) return $response;
        $body = (string) $response->getBody();
        if (strlen($body) > 2000000) return $response;
        try {
            $hash = Projection::fingerprint($body);
        } catch (\JsonException) {
            return $response;
        }
        return $response->withHeader('X-Live-Record-Fingerprint', $hash)
            ->withHeader('Access-Control-Expose-Headers', trim($response->getHeaderLine('Access-Control-Expose-Headers') . ', X-Live-Record-Fingerprint', ', '));
    }
}
