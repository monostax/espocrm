<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import\{Discovery, Documents, Failure, Files, OAuth, State};

/** Normal CRM authentication on every route, including each media range and OAuth callback. */
class MeetingGoogleImport extends \Espo\Modules\FeatureRecordKnowledge\Controllers\Internal
{
    public function __construct(private Discovery $discovery, private Documents $documents, private State $state,
        private OAuth $oauth, private Files $files) {}

    private function identity(Request $request): array
    {
        return [(int) $request->getQueryParam('accountId'), (string) ($request->getRouteParam('meetingId') ?? $request->getQueryParam('meetingId'))];
    }

    private function safe(Response $response, callable $operation): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        try { return (object) $operation(); }
        catch (Failure $e) {
            $response->setStatus(in_array($e->category, ['denied', 'reconnect'], true) ? 403 : 422);
            return (object) ['error' => $e->category];
        }
    }

    public function getActionConnections(Request $request, Response $response): object
    {
        return $this->safe($response, fn () => $this->discovery->connections(...$this->identity($request)));
    }

    public function postActionConnect(Request $request, Response $response): object
    {
        [$account, $meeting] = $this->identity($request);
        return $this->safe($response, fn () => $this->oauth->begin($account, $meeting, $request));
    }

    public function getActionCallback(Request $request, Response $response): void
    {
        $data = $this->oauth->finish($request);
        $origin = $data['origin'];
        unset($data['origin']);
        $data['type'] = 'meeting-google-connected';
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
        $message = json_encode($data, $flags);
        $target = json_encode($origin, $flags);
        $nonce = base64_encode(random_bytes(24));
        $response->setHeader('Content-Type', 'text/html; charset=utf-8')->setHeader('Cache-Control', 'no-store')
            ->setHeader('Referrer-Policy', 'no-referrer')->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'; script-src 'nonce-$nonce'; frame-ancestors 'none'; base-uri 'none'")
            ->writeBody('<!doctype html><html><head><title>Google Meet</title></head><body><script nonce="' . $nonce . '">' .
                "if(window.opener){window.opener.postMessage($message,$target);}window.close();" . '</script></body></html>');
    }

    public function getActionSessions(Request $request, Response $response): object
    {
        [$account, $meeting] = $this->identity($request);
        return $this->safe($response, fn () => $this->discovery->sessions($account, $meeting,
            (string) $request->getQueryParam('credentialId'), $request->getQueryParam('cursor')));
    }

    public function postActionPreview(Request $request, Response $response): object
    {
        [$account, $meeting] = $this->identity($request);
        $body = $request->getParsedBody();
        return $this->safe($response, fn () => $this->discovery->preview($account, $meeting,
            (string) ($body->credentialId ?? ''), (string) ($body->conference ?? '')));
    }

    public function postActionStart(Request $request, Response $response): object
    {
        [$account, $meeting] = $this->identity($request);
        $result = $this->safe($response, fn () => $this->state->start($account, $meeting, $request->getParsedBody()));
        if ($response->getStatusCode() === 200) $response->setStatus(202);
        return $result;
    }

    public function postActionRetry(Request $request, Response $response): object { return $this->postActionStart($request, $response); }

    public function getActionStatus(Request $request, Response $response): object
    {
        return $this->safe($response, fn () => $this->state->status(...$this->identity($request)));
    }

    public function getActionTranscript(Request $request, Response $response): object
    {
        return $this->safe($response, fn () => $this->documents->read(...$this->identity($request)));
    }

    public function putActionTranscript(Request $request, Response $response): object
    {
        [$account, $meeting] = $this->identity($request);
        $version = $request->getHeader('X-Version-Number');
        if ($version === null || !ctype_digit($version)) throw new BadRequest('X-Version-Number is required.');
        return $this->safe($response, fn () => $this->documents->save($account, $meeting, $request->getParsedBody(), (int) $version));
    }

    public function getActionPlayback(Request $request, Response $response): void
    {
        [$account, $meeting] = $this->identity($request);
        $this->files->playback($account, $meeting, (string) $request->getRouteParam('unitId'), $request, $response);
    }

    public function headActionPlayback(Request $request, Response $response): void { $this->getActionPlayback($request, $response); }
}
