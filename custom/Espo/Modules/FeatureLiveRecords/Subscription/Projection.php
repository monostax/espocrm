<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Subscription;

/** Bounded, read-only API projections. No arbitrary URLs, methods or controller actions. */
final class Projection
{
    public static function accepts(string $path): bool
    {
        if (strlen($path) > 512 || !preg_match('~^/[A-Za-z0-9_/-]+$~D', $path)) return false;
        $id = '[a-zA-Z0-9_-]+';
        $type = '(?:Contact|Account|Opportunity|Task|Meeting|Call|Initiative|JourneyRecord|Note|Document|ContactChannelIdentity|InitiativeRelation|CrmTag|Funnel|OpportunityStage|InitiativeStage|InitiativeType|Journey|JourneyStage|Playbook|PlaybookStep|PlaybookRun|PlaybookRunStep)';
        if (preg_match("~^/$type(?:/$id)?$~D", $path) && !str_ends_with($path, '/action')) return true;
        if (!str_contains($path, '/action/') && preg_match("~^/$type/$id/(?:inbox|contacts|accounts|opportunities|activities|relations|stream|discussion|readState|activityCount|contactCount|chatwootConversationsForUser|playbooks|versions|replies)$~D", $path)) return true;
        if (preg_match("~^/(?:Opportunity|Contact|Account|Initiative)/$id/threads/$id$~D", $path)) return true;
        if (preg_match("~^/ActivityInbox(?:/(?:Task|Meeting|Call)/$id(?:/(?:stream|discussion|readState|recurrence|conversations))?)?$~D", $path)) return true;
        if (preg_match("~^/MeetingInbox(?:/$id(?:/stream)?)?$~D", $path) && !preg_match('~/(?:action|posts|readState|bulkReadState)$~D', $path)) return true;
        if (preg_match('~^/(?:Contact|Account|Initiative)/action/(?:inbox|groupSummary|navigationCounts|inboxMetadata|inboxOptions)$~D', $path)) return true;
        if (preg_match("~^/JourneyRelationships/(?:Contact|Account|Opportunity|Initiative)/$id(?:/(?:options|$id))?$~D", $path)) return true;
        if (preg_match("~^/ChatwootConversation/$id/(?:contacts|accounts|opportunities|tasks|calls|meetings)$~D", $path)) return true;
        return in_array($path, [
            '/Opportunity/action/activitySummary', '/Opportunity/action/groupSummary',
            '/Opportunity/action/spreadsheetSummary', '/Opportunity/action/navigationCounts',
            '/RecordKnowledge/relations', '/RecordKnowledge/overview', '/RecordKnowledge/schema',
            '/RecordKnowledge/iconOptions', '/EditorReference/backlinks', '/EditorReference/search',
            '/Contact/inbox', '/Account/inbox', '/Initiative/inbox',
            '/ActivityInbox/counts', '/ActivityInbox/metadata', '/ActivityInbox/options',
            '/MeetingInbox/counts', '/MeetingInbox/metadata', '/MeetingInbox/options',
            '/Notification/action/notReadCount',
        ], true);
    }

    public static function decode(string $topic): array
    {
        if (!str_starts_with($topic, 'liveQuery.') || strlen($topic) > 24000) {
            throw new \InvalidArgumentException('Invalid projection.');
        }
        $encoded = substr($topic, 10);
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $encoded)) throw new \InvalidArgumentException('Invalid projection.');
        $data = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_diff(array_keys($data), ['path', 'query', 'fingerprint']) ||
            !is_string($data['path'] ?? null) || !self::accepts($data['path']) ||
            !is_string($data['query'] ?? null) || strlen($data['query']) > 16000 || !is_string($data['fingerprint'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D', $data['fingerprint'])) {
            throw new \InvalidArgumentException('Unsupported projection.');
        }
        return $data;
    }

    public static function fingerprint(string $body): string
    {
        return hash('sha256', json_encode(self::canonical(json_decode($body, false, 512, JSON_THROW_ON_ERROR)),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) return array_map(self::canonical(...), $value);
        if (!$value instanceof \stdClass) return $value;
        $fields = get_object_vars($value);
        uksort($fields, static function ($a, $b): int {
            $index = static fn ($key): bool => ctype_digit((string) $key) && (string) (int) $key === (string) $key && (int) $key < 4294967295;
            if ($index($a) && $index($b)) return (int) $a <=> (int) $b;
            if ($index($a) !== $index($b)) return $index($a) ? -1 : 1;
            return strcmp((string) $a, (string) $b);
        });
        return (object) array_map(self::canonical(...), $fields);
    }
}
