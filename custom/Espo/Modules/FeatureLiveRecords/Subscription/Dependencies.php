<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Subscription;

/**
 * Which change hints can alter a projection, so the socket re-checks only those.
 * This narrows re-checks, never what a viewer can read: every check still runs
 * the viewer's own API read under ACL. Anything not provably covered returns
 * null and keeps tenant-wide re-checks.
 *
 * Only unfiltered first pages of conversation relationship panels qualify:
 * their membership is exactly the link table, so a hint matters if it names a
 * record of the panel's entity type (a possible new member, or an access change
 * on one) or any record id the response already shows.
 */
final class Dependencies
{
    private const PANEL = '~^/ChatwootConversation/([a-zA-Z0-9_-]+)/(opportunities|tasks|calls|meetings|appointments)$~D';
    private const QUERY_KEYS = ['offset', 'maxSize', 'orderBy', 'order', 'select'];
    public const MAX_IDS = 400;
    // The socket kills a check whose result line passes 16KB, which would stall
    // every projection batched behind it (revocations included). Stay well under.
    public const MAX_BYTES = 8192;

    /**
     * @param callable(string $entityType, string $link): ?string $linkEntity
     * @return ?array{types: list<string>, ids: list<string>}
     */
    public static function of(string $path, string $query, string $body, callable $linkEntity): ?array
    {
        if (!preg_match(self::PANEL, $path, $match)) return null;
        parse_str($query, $params);
        if (array_diff(array_keys($params), self::QUERY_KEYS)) return null;
        // A later page also shifts when an earlier member is removed.
        if ((int) ($params['offset'] ?? 0) !== 0) return null;
        $entity = $linkEntity('ChatwootConversation', $match[2]);
        if (!is_string($entity) || !preg_match('/^[A-Z][a-zA-Z0-9]{0,63}$/D', $entity)) return null;
        try {
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!$data instanceof \stdClass || !is_array($data->list ?? null)) return null;
        $ids = [$match[1] => true];
        if (!self::collect($data, $ids)) return null;
        $deps = ['types' => [$entity], 'ids' => array_map('strval', array_keys($ids))];
        return strlen(json_encode($deps, JSON_THROW_ON_ERROR)) <= self::MAX_BYTES ? $deps : null;
    }

    /** Ids under id/*Id keys, *Ids lists and *Names maps (Espo link fields). False past the cap. */
    private static function collect(mixed $value, array &$ids): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) if (!self::collect($item, $ids)) return false;
            return true;
        }
        if (!$value instanceof \stdClass) return true;
        foreach (get_object_vars($value) as $key => $item) {
            $key = (string) $key;
            if (($key === 'id' || str_ends_with($key, 'Id')) && is_string($item)) {
                self::add($item, $ids);
            } elseif (str_ends_with($key, 'Ids') && is_array($item)) {
                foreach ($item as $id) if (is_string($id)) self::add($id, $ids);
            } elseif (str_ends_with($key, 'Names') && $item instanceof \stdClass) {
                foreach (array_keys(get_object_vars($item)) as $id) self::add((string) $id, $ids);
            }
            if (!self::collect($item, $ids)) return false;
        }
        return count($ids) <= self::MAX_IDS;
    }

    private static function add(string $id, array &$ids): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id)) $ids[$id] = true;
    }
}
