<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\Api;

use Espo\Core\Utils\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Throwable;

/** Customer endpoints must not forward service diagnostics in bodies or reason headers. */
final class CustomerResponse implements MiddlewareInterface
{
    public function __construct(private Log $log) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $reason = null;
        try {
            $response = $handler->handle($request);
            if ($response->getStatusCode() < 400) return $response->withHeader('Cache-Control', 'private, no-store');
            $status = $response->getStatusCode();
            $reason = $this->verificationReason($request, (string) $response->getBody());
            $this->log->warning('Customer credit request returned an error.', ['status' => $status]);
        } catch (Throwable $e) {
            if ($e instanceof \Espo\Core\Exceptions\Conflict) {
                $reason = $this->verificationReason($request, (string) $e->getBody());
            }
            $this->log->error('Customer credit request failed.', ['exception' => $e]);
            $status = match (true) {
                $e instanceof \Espo\Core\Exceptions\BadRequest, $e instanceof \InvalidArgumentException => 400,
                $e instanceof \Espo\Core\Exceptions\Unauthorized => 401,
                $e instanceof \Espo\Core\Exceptions\Forbidden => 403,
                $e instanceof \Espo\Core\Exceptions\NotFound => 404,
                $e instanceof \Espo\Core\Exceptions\Conflict => 409,
                $e instanceof \Espo\Core\Exceptions\ServiceUnavailable => 503,
                default => 500,
            };
        }
        $code = match ($status) {
            400, 422 => 'invalid_request', 401 => 'authentication_required', 403 => 'access_denied',
            404 => 'not_found', 409 => 'request_conflict', 429 => 'rate_limited', default => 'request_unavailable',
        };
        $response = new Response($status);
        $response->getBody()->write(json_encode(['error' => $code, ...($reason === null ? [] : ['reason' => $reason])], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Status-Reason', $code);
    }

    private function verificationReason(ServerRequestInterface $request, string $body): ?string
    {
        if (!preg_match('~/Credit(?:Phone|WhatsApp)Verification(?:/|$)~', $request->getUri()?->getPath() ?? '')) return null;
        $reason = json_decode($body, true)['reason'] ?? null;
        return in_array($reason, ['context_changed', 'ownership_rejected', 'missing_name', 'invalid_phone',
            'unsupported_country', 'invalid_date_of_birth', 'verification_disabled', 'tenant_not_allowlisted',
            'provider_unavailable', 'kill_switch', 'cooldown_active', 'rate_limited', 'insufficient_credits'], true)
            ? $reason : null;
    }
}
