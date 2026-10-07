<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Inbox;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;

/** Bounded read-state writes with per-record authorization and partial results. */
class BulkReadState
{
    public static function run(object $body, callable $mark): object
    {
        $ids = $body->ids ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100 || !is_bool($body->unread ?? null)) {
            throw new BadRequest('Expected up to 100 IDs and an unread boolean.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]+(?::[a-zA-Z0-9_-]+)?$/D', $id) || strlen($id) > 128) {
                throw new BadRequest('Invalid record ID.');
            }
        }
        $states = [];
        $failed = [];
        foreach (array_unique($ids) as $id) {
            try {
                $states[$id] = $mark($id);
            } catch (BadRequest|Conflict|Forbidden|NotFound $e) {
                $failed[] = $id;
            }
        }
        return (object) ['states' => (object) $states, 'failedIds' => $failed];
    }
}
