<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredits\Enrichment\QuoteInput;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use Espo\Modules\FeatureCredits\Enrichment\CustomerProjection;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class CreditEnrichmentQuotes
{
    public function __construct(private Quotes $quotes) {}

    public function postActionQuote(Request $request, Response $response): object
    {
        return $this->command($request, $response, 'quote');
    }

    public function postActionConfirm(Request $request, Response $response): object
    {
        return $this->command($request, $response, 'confirm');
    }

    public function postActionAdmit(Request $request, Response $response): object
    {
        return $this->command($request, $response, 'admit');
    }

    private function command(Request $request, Response $response, string $action): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $raw = $request->getBodyContents() ?? '';
        if (strlen($raw) > 4096) throw new BadRequest('Quote command is too large.');
        try {
            $input = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
            if (!$input instanceof stdClass) throw new InvalidArgumentException('Enrichment command required.');
            if ($action === 'quote') return (object) CustomerProjection::quote($this->quotes->quote(new QuoteInput($input)));
            if (array_diff(array_keys(get_object_vars($input)), ['tenantId', 'quoteId']) ||
                !is_string($input->tenantId ?? null) || !is_string($input->quoteId ?? null)) {
                throw new InvalidArgumentException('Only tenantId and quoteId are accepted.');
            }
            return (object) CustomerProjection::quote($this->quotes->$action($input->tenantId, $input->quoteId));
        } catch (InvalidArgumentException | JsonException | \OverflowException | \Brick\Math\Exception\MathException $e) {
            throw new BadRequest($e->getMessage());
        }
    }
}
