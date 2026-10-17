<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;

/** Embedded-only Appointment operations over the native record service. */
class AppointmentInbox extends ActivityInbox
{
    protected const INBOX_TYPES = ['Appointment'];
    protected const RECORD_TYPES = ['Appointment'];

    public function getActionCounts(Request $request): object { throw new NotFound(); }
    public function postActionRecurrencePreview(Request $request): object { throw new NotFound(); }

    protected function recordType(Request $request): string
    {
        if (in_array($request->getRouteParam('id'), ['counts', 'metadata', 'options', 'bulkReadState', 'action', 'posts', 'readState', 'stream', 'recurrence'], true)) {
            throw new BadRequest('Reserved inbox path.');
        }
        $body = $request->getParsedBody();
        foreach ([$request->getQueryParam('type'), $body->type ?? null, $body->entityType ?? null] as $type) {
            if ($type !== null && $type !== 'Appointment') throw new BadRequest('Invalid inbox activity type.');
        }
        return 'Appointment';
    }

    protected function filters(Request $request): array
    {
        $this->recordType($request);
        foreach (['assignee_tab', 'assigned_user', 'assignedUserId'] as $filter) {
            if ($request->getQueryParam($filter) !== null) throw new BadRequest('Appointment assignment is derived.');
        }
        return parent::filters($request);
    }

    public function getActionOptions(Request $request): object
    {
        $this->recordType($request);
        if (!in_array($request->getQueryParam('entity'), ['Contact', 'User', 'Team', 'ChatwootConversation'], true)) {
            throw new BadRequest('Invalid Appointment option type.');
        }
        return parent::getActionOptions($request);
    }

    public function postActionBulkReadState(Request $request): object
    {
        $this->recordType($request);
        return parent::postActionBulkReadState($request);
    }
}
