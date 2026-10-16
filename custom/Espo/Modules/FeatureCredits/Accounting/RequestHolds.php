<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\Conflict;
use PDO;

/** Shrink a terminal request's hold under WalletLock. Authorization history and
 * original grant attribution survive; no usage is posted before final settlement. */
final class RequestHolds
{
    public static function trim(PDO $pdo, string $tenantId, string $requestId, string $now): void
    {
        $q = $pdo->prepare('SELECT q.*, u.state AS usage_state, r.state AS reservation_state, r.reserved_credits AS reservation_held
            FROM credit_request q JOIN credit_usage u ON u.id=q.usage_id AND u.tenant_id=q.tenant_id
            JOIN credit_reservation r ON r.id=q.reservation_id AND r.tenant_id=q.tenant_id
            WHERE q.id=? AND q.tenant_id=? AND q.deleted=FALSE AND u.deleted=FALSE AND r.deleted=FALSE');
        $q->execute([$requestId, $tenantId]);
        $request = $q->fetch(PDO::FETCH_ASSOC);
        if (!$request || $request['usage_state'] !== 'admitted' || $request['reservation_state'] !== 'held') return;
        if ($request['completed_at'] === null || $request['outcome_record'] === null) return;
        $zero = Amount::fromString('0');
        if ($request['billing_state'] === 'waived') {
            $target = $zero;
        } elseif ($request['billing_state'] === 'billable' && $request['metering_state'] === 'measured' && $request['priced_credits_exact'] !== null) {
            // Ceiling is a hold, not a per-request charge. Final operation rounding stays unchanged.
            $target = Amount::reservation(BigDecimal::of($request['priced_credits_exact']));
            $bound = Amount::fromString($request['authorized_credits']);
            if ($target->compareTo($bound) > 0) {
                if (!NativeSearchDebt::permitted(json_decode($request['pricing_snapshot'], true, 512, JSON_THROW_ON_ERROR))) {
                    throw new Conflict('Measured request exceeds its authorization.');
                }
                $target = $bound;
            }
        } else {
            return; // An unresolved/in-flight request retains its own bound, never its siblings' excess.
        }
        $q = $pdo->prepare('SELECT a.*, g.expires_at, g.deleted AS grant_deleted, g.remaining_credits AS grant_remaining,
            g.reserved_credits AS grant_held FROM credit_allocation a
            JOIN credit_grant g ON g.id=a.grant_id AND g.tenant_id=a.tenant_id
            WHERE a.request_id=? AND a.tenant_id=? ORDER BY CASE WHEN g.expires_at IS NULL THEN 1 ELSE 0 END, g.expires_at, g.id, a.id FOR UPDATE');
        $q->execute([$requestId, $tenantId]);
        $allocations = $q->fetchAll(PDO::FETCH_ASSOC);
        $authorized = $held = $zero;
        foreach ($allocations as $a) {
            if ($a['deleted'] || $a['grant_deleted'] || $a['reservation_id'] !== $request['reservation_id']) {
                throw new Conflict('Invalid request hold ownership.');
            }
            WalletLock::assertProjection($a['grant_remaining'], $a['grant_held']);
            $amount = Amount::fromString($a['reserved_credits']);
            $authorized = $authorized->plus($amount);
            if ($a['settled_at'] === null) {
                if ($a['consumed_credits'] !== '0.0000' || $a['released_credits'] !== '0.0000' || $a['expired_credits'] !== '0.0000' ||
                    $a['debit_transaction_id'] !== null || $a['expiration_transaction_id'] !== null) throw new Conflict('Invalid open request hold.');
                $held = $held->plus($amount);
            } elseif ($a['consumed_credits'] !== '0.0000' || $a['debit_transaction_id'] !== null ||
                $amount->compareTo(Amount::fromString($a['released_credits'])->plus(Amount::fromString($a['expired_credits']))) !== 0) {
                throw new Conflict('Invalid released request hold.');
            }
        }
        if ((string) $authorized !== $request['authorized_credits'] || $held->compareTo($target) < 0 ||
            $held->compareTo(Amount::fromString($request['reservation_held'])) > 0) {
            throw new Conflict('Request hold does not cover its charge.');
        }
        $left = $target;
        $released = $expiredTotal = $zero;
        foreach ($allocations as $a) {
            if ($a['settled_at'] !== null) continue;
            $amount = Amount::fromString($a['reserved_credits']);
            $keep = $amount->compareTo($left) < 0 ? $amount : $left;
            $left = $left->minus($keep);
            $surplus = $amount->minus($keep);
            if ($surplus->compareTo($zero) === 0) continue;
            $expired = $a['expires_at'] !== null && $a['expires_at'] <= $now;
            // Split rather than erase authorization history. All request allocations
            // (open + closed) still sum exactly to the immutable authorized bound.
            $id = RecordId::generate();
            $posting = $expired ? RecordId::generate() : null;
            $key = 'trim:' . $a['id'];
            $pdo->prepare('UPDATE credit_allocation SET reserved_credits=? WHERE id=?')->execute([(string) $keep, $a['id']]);
            $pdo->prepare('INSERT INTO credit_allocation (id,tenant_id,grant_id,reservation_id,allocation_key,request_id,
                reserved_credits,consumed_credits,released_credits,expired_credits,expiration_transaction_id,created_at,settled_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id, $tenantId, $a['grant_id'], $a['reservation_id'], $key, $requestId,
                    (string) $surplus, '0.0000', $expired ? '0.0000' : (string) $surplus, $expired ? (string) $surplus : '0.0000', $posting, $now, $now]);
            if ($expired) {
                $pdo->prepare('INSERT INTO credit_transaction (id,tenant_id,type,idempotency_key,input_hash,credits,usage_id,
                    grant_id,allocation_id,occurred_at,posted_at,evidence) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$posting, $tenantId, 'expiration', $key, hash('sha256', "$tenantId:$key:$surplus"),
                        (string) $zero->minus($surplus), $request['usage_id'], $a['grant_id'], $id, $now, $now,
                        json_encode(['reason' => 'terminal_request_surplus', 'requestId' => $requestId], JSON_THROW_ON_ERROR)]);
                $expiredTotal = $expiredTotal->plus($surplus);
            }
            $pdo->prepare('UPDATE credit_grant SET reserved_credits=reserved_credits-?,remaining_credits=remaining_credits-? WHERE id=?')
                ->execute([(string) $surplus, $expired ? (string) $surplus : '0.0000', $a['grant_id']]);
            $released = $released->plus($surplus);
        }
        if ($released->compareTo($zero) === 0) return;
        $pdo->prepare('UPDATE credit_reservation SET reserved_credits=reserved_credits-?,modified_at=? WHERE id=?')
            ->execute([(string) $released, $now, $request['reservation_id']]);
        $pdo->prepare('UPDATE tenant_credit_balance SET reserved_credits=reserved_credits-?,balance=balance-?,modified_at=? WHERE tenant_id=?')
            ->execute([(string) $released, (string) $expiredTotal, $now, $tenantId]);
        $q = $pdo->prepare('SELECT DISTINCT g.remaining_credits,g.reserved_credits FROM credit_grant g
            JOIN credit_allocation a ON a.grant_id=g.id AND a.tenant_id=g.tenant_id WHERE a.request_id=? AND a.tenant_id=?');
        $q->execute([$requestId, $tenantId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $grant) WalletLock::assertProjection($grant['remaining_credits'], $grant['reserved_credits']);
        $q = $pdo->prepare('SELECT balance,reserved_credits FROM tenant_credit_balance WHERE tenant_id=?');
        $q->execute([$tenantId]);
        $wallet = $q->fetch(PDO::FETCH_ASSOC);
        WalletDebt::assertWallet($pdo, $tenantId, $wallet['balance'], $wallet['reserved_credits']);
    }
}
