<?php

namespace Espo\Modules\Chatwoot\Services;

/** Current source state, separate from episode lifecycle history and CRM ACL teams. */
class ConversationReportSnapshot
{
    /** @return array<string, ?string> */
    public static function fromPayload(array $source): array
    {
        $data = [];

        // Unknown labels are different from a known-empty label list.
        if (array_key_exists('labels', $source)) {
            $data['currentTags'] = implode(', ', array_unique($source['labels'] ?? []));
        }
        // Called with full conversation GET/filter responses. Chatwoot's
        // serializer OMITS meta.team when unassigned instead of returning null.
        $data['currentTeamName'] = $source['meta']['team']['name'] ?? null;

        return $data;
    }
}
