<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;

/** Call URLs share the same record, ACL, version and discussion operations. */
class CallInbox extends ActivityInbox
{
    protected const INBOX_TYPES = ['Call'];
    protected const RECORD_TYPES = ['Call'];

    protected function recordType(Request $request): string
    {
        if (in_array($request->getRouteParam('id'), ['counts', 'metadata', 'options', 'bulkReadState', 'action', 'posts', 'readState'], true)) {
            throw new BadRequest('Reserved inbox path.');
        }
        $body = $request->getParsedBody();
        foreach ([$request->getQueryParam('type'), $body->type ?? null, $body->entityType ?? null] as $type) {
            if ($type !== null && $type !== 'Call') throw new BadRequest('Invalid inbox activity type.');
        }
        return 'Call';
    }

    public function getActionOptions(Request $request): object
    {
        $this->recordType($request);
        return parent::getActionOptions($request);
    }

    public function postActionBulkReadState(Request $request): object
    {
        $this->recordType($request);
        return parent::postActionBulkReadState($request);
    }
}
