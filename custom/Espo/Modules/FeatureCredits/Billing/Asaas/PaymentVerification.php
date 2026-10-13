<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing\Asaas;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Billing\MonthlyPeriod;

/** Only authoritative GET data enters here. Webhook status/value are never payment authority. */
final class PaymentVerification
{
    public static function verify(array $data, string $id, Command $original, string $externalId): array
    {
        $body = $original->body;
        if (($data['object'] ?? null) !== 'payment' || ($data['id'] ?? null) !== $id ||
            ($data['customer'] ?? null) !== $body['customer'] || !empty($data['installment']) ||
            !self::money($data['value'] ?? null, $body['value']) ||
            (isset($data['originalValue']) && !self::money($data['originalValue'], $body['value']))) {
            throw new Conflict('Authoritative payment does not match original commercial terms.');
        }
        $method = $data['billingType'] ?? null;
        // A boleto may be paid by PIX. UNDEFINED permits the supported launch methods only.
        $methods = match ($body['billingType']) {
            'UNDEFINED' => ['PIX', 'BOLETO', 'CREDIT_CARD'], 'BOLETO' => ['BOLETO', 'PIX'], default => [$body['billingType']],
        };
        if (!in_array($method, $methods, true)) throw new Conflict('Unexpected payment method.');
        $due = $data['originalDueDate'] ?? null;
        if (!is_string($due)) throw new Conflict('Original payment due date is required.');
        try {
            GrantInput::timestamp($due . ' 00:00:00');
            if ($original->kind === 'subscription') {
                if (($data['subscription'] ?? null) !== $externalId) throw new \InvalidArgumentException();
                $period = MonthlyPeriod::forDueDate($body['nextDueDate'], $due);
            } else {
                if ($original->kind !== 'payment' || $externalId !== $id || !empty($data['subscription']) ||
                    ($data['externalReference'] ?? null) !== $body['externalReference'] || $due !== $body['dueDate']) throw new \InvalidArgumentException();
                $period = null;
            }
        } catch (\InvalidArgumentException) { throw new Conflict('Payment has no verified original billing period.'); }
        $status = $data['status'] ?? null;
        if (!is_string($status) || !preg_match('/^[A-Z_]{1,64}$/D', $status) || !is_bool($data['deleted'] ?? null)) {
            throw new Conflict('Incomplete authoritative payment status.');
        }
        $won = self::wonDispute($data, $body['value']);
        $review = $data['deleted'] || !empty($data['refunds']) || (!empty($data['chargeback']) && !$won) || !empty($data['split']) ||
            !in_array($status, ['PENDING', 'AWAITING_RISK_ANALYSIS', 'AUTHORIZED', 'CONFIRMED', 'RECEIVED', 'OVERDUE', 'DUNNING_REQUESTED'], true);
        foreach (['discount', 'fine', 'interest'] as $adjustment) {
            if (isset($data[$adjustment]) && (!is_array($data[$adjustment]) || !self::money($data[$adjustment]['value'] ?? null, '0'))) $review = true;
        }
        if (isset($data['interestValue']) && !self::money($data['interestValue'], '0')) $review = true;
        $paid = !$review && ($status === 'RECEIVED' || ($status === 'CONFIRMED' && in_array($method, ['BOLETO', 'CREDIT_CARD'], true)));
        // PIX CONFIRMED is precautionary review, not the PIX RECEIVED settlement signal.
        if ($method === 'PIX' && $status === 'CONFIRMED') $review = true;
        $refund = self::partialRefund($data, $body['value']);
        if ($refund !== null) {
            $paid = $status === 'RECEIVED' || ($status === 'CONFIRMED' && in_array($method, ['BOLETO', 'CREDIT_CARD'], true));
            $review = !$paid;
        }
        return ['paymentId' => $id, 'customer' => $body['customer'], 'subscription' => $data['subscription'] ?? null,
            'originalDueDate' => $due, 'value' => $body['value'], 'billingType' => $method, 'status' => $status,
            'period' => $period, 'paid' => $paid, 'review' => $review,
            'fullReversal' => self::fullReversal($data, $body['value']), 'refund' => $refund];
    }

    private static function wonDispute(array $data, string $value): bool
    {
        $c = $data['chargeback'] ?? null;
        return in_array($data['status'], ['RECEIVED', 'CONFIRMED'], true) && is_array($c) &&
            ($c['status'] ?? null) === 'REVERSED' && ($c['payment'] ?? null) === $data['id'] &&
            ($c['customerAccount'] ?? null) === $data['customer'] && self::money($c['value'] ?? null, $value) &&
            empty($c['installment']) && is_string($c['id'] ?? null) && (bool) preg_match('/^[a-zA-Z0-9_-]{1,104}$/D', $c['id']);
    }

    private static function partialRefund(array $data, string $value): ?array
    {
        if (!in_array($data['status'], ['RECEIVED', 'CONFIRMED'], true) || $data['deleted'] || !empty($data['split']) || !empty($data['chargeback'])) return null;
        foreach (['discount', 'fine', 'interest'] as $field) {
            if (isset($data[$field]) && (!is_array($data[$field]) || !self::money($data[$field]['value'] ?? null, '0'))) return null;
        }
        if (isset($data['interestValue']) && !self::money($data['interestValue'], '0')) return null;
        $refunds = $data['refunds'] ?? null;
        if (!is_array($refunds) || !array_is_list($refunds) || !$refunds || count($refunds) > 100) return null;
        $total = BigDecimal::zero();
        foreach ($refunds as $refund) {
            if (!is_array($refund) || ($refund['status'] ?? null) !== 'DONE' || !empty($refund['refundedSplits']) ||
                !is_string($refund['value'] ?? null) || !preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $refund['value'])) return null;
            $amount = BigDecimal::of($refund['value']);
            if (!$amount->isPositive()) return null;
            $total = $total->plus($amount);
        }
        if (!$total->isLessThan($value)) return null;
        return ['kind' => 'refund', 'status' => 'PARTIAL', 'value' => (string) $total->toScale(2)];
    }

    /** Full final losses only. Partial refunds, open disputes and ambiguous adjustments stay in review. */
    private static function fullReversal(array $data, string $value): ?array
    {
        if ($data['status'] !== 'REFUNDED' || $data['deleted'] || !empty($data['split'])) return null;
        foreach (['discount', 'fine', 'interest'] as $field) {
            if (isset($data[$field]) && (!is_array($data[$field]) || !self::money($data[$field]['value'] ?? null, '0'))) return null;
        }
        if (isset($data['interestValue']) && !self::money($data['interestValue'], '0')) return null;
        if (!empty($data['chargeback'])) {
            $c = $data['chargeback'];
            if (!is_array($c) || !in_array($c['status'] ?? null, ['DONE', 'DISPUTE_LOST'], true) ||
                ($c['payment'] ?? null) !== $data['id'] || ($c['customerAccount'] ?? null) !== $data['customer'] ||
                !self::money($c['value'] ?? null, $value) || !empty($c['installment']) ||
                !is_string($c['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,104}$/D', $c['id'])) return null;
            // Mixed refund/chargeback claims require explicit reconciliation, never double subtraction.
            if (!empty($data['refunds'])) return null;
            return ['kind' => 'chargeback', 'id' => $c['id'], 'status' => $c['status'], 'value' => $value];
        }
        if (isset($data['refunds']) && $data['refunds'] !== []) {
            if (!is_array($data['refunds']) || !array_is_list($data['refunds']) || count($data['refunds']) > 100) return null;
            $total = BigDecimal::zero();
            foreach ($data['refunds'] as $refund) {
                if (!is_array($refund) || ($refund['status'] ?? null) !== 'DONE' || !empty($refund['refundedSplits']) ||
                    !is_string($refund['value'] ?? null) || !preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $refund['value'])) return null;
                $amount = BigDecimal::of($refund['value']);
                if (!$amount->isPositive()) return null;
                $total = $total->plus($amount);
            }
            if ($total->compareTo(BigDecimal::of($value)) !== 0) return null;
        }
        return ['kind' => 'refund', 'status' => 'REFUNDED', 'value' => $value];
    }

    private static function money(mixed $value, string $expected): bool
    {
        return is_string($value) && preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $value) &&
            BigDecimal::of($value)->compareTo(BigDecimal::of($expected)) === 0;
    }
}
