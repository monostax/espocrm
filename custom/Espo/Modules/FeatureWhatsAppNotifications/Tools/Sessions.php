<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\ORM\Entity;

/**
 * The deck session kept on the subscription (`session`): the subjects passed over today ("Próximo" or acted on), so
 * the next card is a new one, and the card whose reply the person is typing after "Editar".
 */
class Sessions
{
    private const MAX_SKIPPED = 60;

    /** @return list<string> "lane:id" */
    public static function skipped(Entity $subscription): array
    {
        $session = self::read($subscription);
        return ($session['day'] ?? null) === gmdate('Y-m-d') && is_array($session['skip'] ?? null) ? array_values($session['skip']) : [];
    }

    public static function skip(Entity $subscription, string $lane, string $id): void
    {
        $session = self::read($subscription);
        $skip = self::skipped($subscription);
        $skip[] = "{$lane}:{$id}";
        $session['skip'] = array_slice(array_values(array_unique($skip)), -self::MAX_SKIPPED);
        $session['day'] = gmdate('Y-m-d');
        $subscription->set('session', (object) $session);
    }

    public static function editing(Entity $subscription): ?string
    {
        $card = self::read($subscription)['editing'] ?? null;
        return is_string($card) && $card !== '' ? $card : null;
    }

    public static function setEditing(Entity $subscription, ?string $cardId): void
    {
        $session = self::read($subscription);
        $session['editing'] = $cardId;
        $subscription->set('session', (object) $session);
    }

    /** @return array<string, mixed> */
    private static function read(Entity $subscription): array
    {
        $session = $subscription->get('session');
        if (is_object($session)) $session = json_decode((string) json_encode($session), true);
        return is_array($session) ? $session : [];
    }
}
