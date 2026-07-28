<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Reports;

use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Select\Where\Item\Type as WhereType;

/**
 * Shared period + bucketing helpers for AI-linked opportunity digest reports.
 *
 * Runtime where scopes ChatwootAiAgentRun (typically runAt + tenant). Won/Lost
 * amounts additionally require Opportunity.closeDate inside the same calendar
 * window; Open stays a snapshot of currently open AI-touched opps.
 */
final class AiLinkedOpportunityBuckets
{
    /**
     * Extract half-open date bounds [startDate, endDate) from a runAt (or
     * other datetime) range inside the where tree.
     *
     * @return array{start: ?string, end: ?string} Y-m-d strings (null if missing)
     */
    public static function extractDatePeriod(?WhereItem $where, string $attribute = 'runAt'): array
    {
        $start = null;
        $end = null;

        self::walkWhere($where, function (WhereItem $item) use ($attribute, &$start, &$end): void {
            if ($item->getAttribute() !== $attribute) {
                return;
            }

            $type = $item->getType();
            $date = self::toDateString($item->getValue());

            if ($date === null) {
                return;
            }

            if (
                $type === WhereType::GREATER_THAN_OR_EQUALS ||
                $type === WhereType::AFTER ||
                $type === WhereType::GREATER_THAN ||
                $type === WhereType::ON
            ) {
                if ($start === null || strcmp($date, $start) > 0) {
                    $start = $date;
                }

                return;
            }

            if (
                $type === WhereType::LESS_THAN ||
                $type === WhereType::BEFORE
            ) {
                if ($end === null || strcmp($date, $end) < 0) {
                    $end = $date;
                }
            }
        });

        return ['start' => $start, 'end' => $end];
    }

    /**
     * closeDate (Y-m-d) in half-open [start, end). Null closeDate never matches.
     */
    public static function isCloseDateInPeriod(?string $closeDate, ?string $start, ?string $end): bool
    {
        if ($closeDate === null || $closeDate === '') {
            return false;
        }

        $date = substr($closeDate, 0, 10);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        // No bounds → do not count closed money as "in period"
        if ($start === null && $end === null) {
            return false;
        }

        if ($start !== null && strcmp($date, $start) < 0) {
            return false;
        }

        if ($end !== null && strcmp($date, $end) >= 0) {
            return false;
        }

        return true;
    }

    /**
     * @param callable(WhereItem): void $fn
     */
    private static function walkWhere(?WhereItem $item, callable $fn): void
    {
        if ($item === null) {
            return;
        }

        $fn($item);

        $type = $item->getType();

        if ($type !== WhereItem::TYPE_AND && $type !== WhereItem::TYPE_OR) {
            return;
        }

        foreach ($item->getItemList() as $child) {
            self::walkWhere($child, $fn);
        }
    }

    private static function toDateString(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
            return $m[1];
        }

        return null;
    }
}
