<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use PDO;

/** Global per-endpoint windows, claimed in the admission/dispatch transaction, never over HTTP. */
final class ProviderLimit
{
    public static function claim(PDO $pdo, string $now, bool $poll): bool
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Provider rate claims require a transaction.');
        $id = $poll ? 'bounceban-status' : 'bounceban-submit';
        $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? ' ON CONFLICT (id) DO NOTHING' : ' ON DUPLICATE KEY UPDATE id = id';
        $pdo->prepare('INSERT INTO verification_provider_window (id, window_at, request_count) VALUES (?, ?, 0)' . $suffix)
            ->execute([$id, $now]);
        $q = $pdo->prepare('SELECT * FROM verification_provider_window WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        $limit = filter_var(getenv($poll ? 'VERIFICATION_BOUNCEBAN_STATUS_PER_SECOND' : 'VERIFICATION_BOUNCEBAN_SUBMIT_PER_SECOND'), FILTER_VALIDATE_INT);
        $limit = is_int($limit) && $limit >= 1 && $limit <= 25 ? $limit : 25;
        $count = $row['window_at'] === $now ? (int) $row['request_count'] : 0;
        if ($count >= $limit) return false;
        $pdo->prepare('UPDATE verification_provider_window SET window_at = ?, request_count = ? WHERE id = ?')
            ->execute([$now, $count + 1, $id]);
        return true;
    }
}
