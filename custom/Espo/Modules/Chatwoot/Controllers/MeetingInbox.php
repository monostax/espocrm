<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;

/** Meeting URLs share the same record, ACL, version and discussion operations. */
class MeetingInbox extends ActivityInbox
{
    protected const INBOX_TYPES = ['Meeting'];
    protected const RECORD_TYPES = ['Meeting'];

    protected function recordType(Request $request): string
    {
        $body = $request->getParsedBody();
        foreach ([$request->getQueryParam('type'), $body->type ?? null, $body->entityType ?? null] as $type) {
            if ($type !== null && $type !== 'Meeting') throw new BadRequest('Invalid inbox activity type.');
        }
        return 'Meeting';
    }
}
