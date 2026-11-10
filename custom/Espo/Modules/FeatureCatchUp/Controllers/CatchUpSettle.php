<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureCatchUp\Services\Settle;
use stdClass;

/**
 * Analysis at settle (Settle): internal endpoints only the backend calls, signed with the Catch Up secret under their own
 * prefix, so a CRM-to-backend signature can't be replayed here. No browser reaches them.
 */
final class CatchUpSettle
{
    public function __construct(private Settle $settle) {}

    /** A conversation whose customer sent the last message has been quiet for the settle time (Hatchet `catch-up-settle`). */
    public function postActionStart(Request $request): object
    {
        $input = $this->verified($request);
        return (object) $this->settle->start((int) ($input->accountId ?? 0), (int) ($input->conversationId ?? 0));
    }

    /** An analysis start() queued has finished (Hatchet `catch-up-summary`): store its verdict. */
    public function postActionComplete(Request $request): object
    {
        $input = $this->verified($request);
        foreach (['lane', 'id', 'userId', 'timeZone', 'locale', 'requestId'] as $field) {
            if (!is_string($input->$field ?? null)) throw new BadRequest('Invalid completion.');
        }
        return (object) $this->settle->complete($input->lane, $input->id, $input->userId, (int) ($input->accountId ?? 0),
            $input->timeZone, $input->locale, $input->requestId);
    }

    private function verified(Request $request): stdClass
    {
        $secret = getenv('CATCH_UP_BACKEND_SECRET');
        $raw = $request->getBodyContents() ?? '';
        $timestamp = $request->getHeader('X-Catch-Up-Timestamp') ?? '';
        $signature = $request->getHeader('X-Catch-Up-Signature') ?? '';
        if (!$secret || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 ||
            !hash_equals('sha256=' . hash_hmac('sha256', "catch-up-settle-v1.$timestamp.$raw", $secret), $signature)) {
            throw new Forbidden('Invalid Catch Up signature.');
        }
        $input = json_decode($raw);
        if (!$input instanceof stdClass) throw new BadRequest('Invalid request.');
        return $input;
    }
}
