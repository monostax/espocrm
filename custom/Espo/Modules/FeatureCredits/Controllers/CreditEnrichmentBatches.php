<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredits\Enrichment\Batches;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** Bulk enrichment over list selections: preview pages, create, progress, cancel and resume. */
final class CreditEnrichmentBatches
{
    public function __construct(private Batches $batches) {}

    public function getActionList(Request $request, Response $response): object
    {
        return $this->call($response, fn () => $this->batches->list($request->getQueryParams()));
    }

    public function getActionStatus(Request $request, Response $response): object
    {
        return $this->call($response, fn () => $this->batches->status($request->getQueryParams()));
    }

    public function postActionPreview(Request $request, Response $response): object
    {
        return $this->call($response, fn () => $this->batches->preview($this->body($request)));
    }

    public function postActionCreate(Request $request, Response $response): object
    {
        return $this->call($response, fn () => $this->batches->create($this->body($request)));
    }

    public function postActionCancel(Request $request, Response $response): object
    {
        return $this->call($response, fn () => $this->batches->cancel($this->body($request)));
    }

    public function postActionResume(Request $request, Response $response): object
    {
        return $this->call($response, fn () => $this->batches->resume($this->body($request)));
    }

    private function body(Request $request): stdClass
    {
        $raw = $request->getBodyContents() ?? '';
        // 500 record IDs plus settings.
        if (strlen($raw) > 32768) throw new BadRequest('Bulk enrichment request is too large.');
        try {
            $input = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BadRequest($e->getMessage());
        }
        if (!$input instanceof stdClass) throw new BadRequest('Bulk enrichment command required.');
        return $input;
    }

    private function call(Response $response, callable $action): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        try {
            return (object) $action();
        } catch (InvalidArgumentException | \Brick\Math\Exception\MathException $e) {
            throw new BadRequest($e->getMessage());
        }
    }
}
