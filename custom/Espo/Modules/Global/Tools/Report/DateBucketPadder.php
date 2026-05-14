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

declare(strict_types=1);

namespace Espo\Modules\Global\Tools\Report;

use DateTime;
use DateTimeZone;
use Espo\Core\Select\Where\DefaultDateTimeItemTransformer;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Select\Where\Item\Type as WhereType;
use Espo\Core\Utils\Config\ApplicationConfig;
use Espo\Core\Utils\Log;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Tools\Report\GridType\Result as GridResult;
use Throwable;

/**
 * Fills missing date buckets in a Grid report result with zero-valued rows so
 * that charts and tables render a continuous timeline across the filtered
 * date range — even on days/months/etc. that produced no underlying rows.
 *
 * Activation:
 *   - The Report entity has `fillEmptyDateBuckets = true` (custom field
 *     defined in the Global module's entityDefs override).
 *   - The first grouping is a supported periodic date function applied to a
 *     single attribute: `DAY:`, `MONTH:` / `YEAR_MONTH:`, `YEAR:`, `QUARTER:`.
 *   - A concrete date range can be derived from the runtime `WhereItem` for
 *     the same attribute (either an already-resolved `between` / `on`, or a
 *     symbolic form like `lastXDays` resolvable via `DateTimeItemTransformer`).
 *
 * Out of scope (intentionally — easy to add later):
 *   - Padding driven by static report filters (filters stored on the Report
 *     entity rather than passed at runtime). The runtime filter covers the
 *     dashlet-bound and report-page-runtime-filter cases, which is what users
 *     overwhelmingly hit. Static-only date filters are uncommon for charts
 *     that show a "timeline".
 *   - Padding non-periodic groupings (MONTH_NUMBER, DAYOFWEEK, HOUR…). These
 *     are numeric and benefit from a different fill strategy (full domain,
 *     not range-derived).
 *   - Padding the second grouping. The second grouping is usually categorical
 *     (stage, owner) — padding it from a derivable domain is non-trivial and
 *     not commonly needed for "fill empty days" timelines.
 *   - Padding joint-grid results' per-column structure. The grouping is
 *     padded, which is enough for charts; per-column cell padding could be
 *     added if explicit tabular zero-rows are needed.
 */
class DateBucketPadder
{
    /** Supported date functions and the strftime-style format of their bucket keys. */
    private const FUNCTION_LIST = [
        'DAY',          // YYYY-MM-DD
        'MONTH',        // YYYY-MM
        'YEAR_MONTH',   // YYYY-MM (alias of MONTH in some Espo versions)
        'YEAR',         // YYYY
        'QUARTER',      // YYYY_Q
    ];

    public function __construct(
        // `DateTimeItemTransformer` is an interface that Espo binds per-entity
        // via `ConverterFactory` — there's no DI-resolvable global instance,
        // so we depend on the concrete default implementation. Entity-specific
        // transformers (Meeting/Call) tweak attribute aliasing that doesn't
        // affect date bucket math, so the default is the correct choice here.
        private DefaultDateTimeItemTransformer $dateTimeItemTransformer,
        private ApplicationConfig $applicationConfig,
        private Log $log,
    ) {}

    public function pad(GridResult $result, Report $report, ?WhereItem $whereItem): GridResult
    {
        if (!$report->get('fillEmptyDateBuckets')) {
            return $result;
        }

        $groupByList = $result->getGroupByList();

        if ($groupByList === []) {
            return $result;
        }

        $info = $this->parseDateGroupBy($groupByList[0]);

        if ($info === null) {
            return $result;
        }

        $range = $this->extractDateRange($whereItem, $info['attribute']);

        if ($range === null) {
            return $result;
        }

        try {
            $expected = $this->expandKeys($info['function'], $range[0], $range[1]);
        } catch (Throwable $e) {
            $this->log->warning(
                "DateBucketPadder: failed to expand keys for report {$report->getId()}: " .
                $e->getMessage()
            );

            return $result;
        }

        if ($expected === []) {
            return $result;
        }

        return $this->mergeExpectedKeys($result, $expected);
    }

    /**
     * Parse "DAY:createdAt" → ['function' => 'DAY', 'attribute' => 'createdAt'].
     * Returns null when the group-by isn't a single-attribute periodic date
     * function we know how to pad.
     */
    private function parseDateGroupBy(string $groupBy): ?array
    {
        if (!preg_match('/^([A-Z_0-9]+):(.+)$/', $groupBy, $m)) {
            return null;
        }

        $function = $m[1];
        $rest = trim($m[2]);

        if (!in_array($function, self::FUNCTION_LIST, true)) {
            return null;
        }

        // Strip outer parens — some expressions arrive as `DAY:(createdAt)`.
        if (str_starts_with($rest, '(') && str_ends_with($rest, ')')) {
            $rest = substr($rest, 1, -1);
            $rest = trim($rest);
        }

        // Bail on anything that isn't a single bare attribute name — complex
        // expressions like `DAY:(IFNULL:(createdAt, dateCreated))` would need
        // their own range-derivation logic.
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_\.]*$/', $rest)) {
            return null;
        }

        return ['function' => $function, 'attribute' => $rest];
    }

    /**
     * Walk the `where` looking for items targeting `$attribute` that resolve
     * to a concrete date range. Intersects all matches (AND semantics) so the
     * resulting range is the tightest implied by the filter.
     *
     * @return array{string, string}|null `[start_y_m_d, end_y_m_d]` or null
     *     when no usable range can be derived.
     */
    private function extractDateRange(?WhereItem $whereItem, string $attribute): ?array
    {
        if ($whereItem === null) {
            return null;
        }

        $start = null;
        $end = null;

        foreach ($this->flattenAnd($whereItem) as $item) {
            if ($item->getAttribute() !== $attribute) {
                continue;
            }

            $resolved = $this->resolveItemToRange($item);

            if ($resolved === null) {
                continue;
            }

            [$s, $e] = $resolved;

            $start = ($start === null || $s > $start) ? $s : $start;
            $end = ($end === null || $e < $end) ? $e : $end;
        }

        if ($start === null || $end === null || $start > $end) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * Flatten only top-level AND nodes. We don't dive into OR groups because
     * a date range that holds only on one branch of an OR doesn't constrain
     * the whole result. NOT groups are skipped for the same reason.
     *
     * @return WhereItem[]
     */
    private function flattenAnd(WhereItem $item): array
    {
        if ($item->getType() === WhereType::AND) {
            $out = [];

            foreach ($item->getItemList() as $sub) {
                foreach ($this->flattenAnd($sub) as $leaf) {
                    $out[] = $leaf;
                }
            }

            return $out;
        }

        return [$item];
    }

    /**
     * Resolve a single date `WhereItem` (symbolic or already-`between`) to
     * `[start_y_m_d, end_y_m_d]`. Returns null when the item doesn't define
     * a closed range.
     *
     * @return array{string, string}|null
     */
    private function resolveItemToRange(WhereItem $item): ?array
    {
        $type = $item->getType();

        if ($type === WhereType::BETWEEN) {
            $value = $item->getValue();

            if (is_array($value) && isset($value[0], $value[1])) {
                return [$this->toDate($value[0]), $this->toDate($value[1])];
            }

            return null;
        }

        if ($type === WhereType::ON) {
            $value = $item->getValue();

            if (is_string($value)) {
                $d = $this->toDate($value);

                return [$d, $d];
            }

            return null;
        }

        // Symbolic types (lastXDays, currentMonth, today, etc.) need the
        // transformer. The transformer requires a Date/DateTime data object;
        // bail when neither is present (some `equals`-style filters use the
        // attribute without a date wrapper).
        if ($item->getData() === null) {
            return null;
        }

        try {
            $transformed = $this->dateTimeItemTransformer->transform($item);
        } catch (Throwable) {
            return null;
        }

        if ($transformed->getType() !== WhereType::BETWEEN) {
            return null;
        }

        $value = $transformed->getValue();

        if (!is_array($value) || !isset($value[0], $value[1])) {
            return null;
        }

        return [$this->toDate($value[0]), $this->toDate($value[1])];
    }

    /**
     * Extract `YYYY-MM-DD` from `YYYY-MM-DD HH:MM:SS` or pass through.
     * The report engine's DAY/MONTH/etc. groupings already operate in the
     * system timezone, so we use the calendar date emitted by the resolver
     * (which produces UTC timestamps). To stay consistent with the bucket
     * keys the engine returns, we re-project to the configured timezone
     * before slicing.
     */
    private function toDate(string $value): string
    {
        $tz = $this->applicationConfig->getTimeZone();

        try {
            $dt = new DateTime($value, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($tz));

            return $dt->format('Y-m-d');
        } catch (Throwable) {
            // Fallback to a substring of the raw value — covers the case
            // where `$value` is already a bare YYYY-MM-DD.
            return substr($value, 0, 10);
        }
    }

    /**
     * Generate every bucket key implied by `[start, end]` for the given date
     * function.
     *
     * @return string[]
     */
    private function expandKeys(string $function, string $start, string $end): array
    {
        $tz = new DateTimeZone($this->applicationConfig->getTimeZone());

        $dtStart = new DateTime($start, $tz);
        $dtEnd = new DateTime($end, $tz);

        if ($dtStart > $dtEnd) {
            return [];
        }

        $keys = [];

        switch ($function) {
            case 'DAY':
                $cur = clone $dtStart;
                $cur->setTime(0, 0);

                while ($cur <= $dtEnd) {
                    $keys[] = $cur->format('Y-m-d');
                    $cur->modify('+1 day');
                }

                break;

            case 'MONTH':
            case 'YEAR_MONTH':
                $cur = new DateTime($dtStart->format('Y-m-01'), $tz);
                $endKey = $dtEnd->format('Y-m');

                while ($cur->format('Y-m') <= $endKey) {
                    $keys[] = $cur->format('Y-m');
                    $cur->modify('+1 month');
                }

                break;

            case 'YEAR':
                $cur = new DateTime($dtStart->format('Y') . '-01-01', $tz);
                $endKey = $dtEnd->format('Y');

                while ($cur->format('Y') <= $endKey) {
                    $keys[] = $cur->format('Y');
                    $cur->modify('+1 year');
                }

                break;

            case 'QUARTER':
                $startYear = (int) $dtStart->format('Y');
                $startQuarter = (int) ceil(((int) $dtStart->format('n')) / 3);
                $endYear = (int) $dtEnd->format('Y');
                $endQuarter = (int) ceil(((int) $dtEnd->format('n')) / 3);

                $year = $startYear;
                $quarter = $startQuarter;

                while ($year < $endYear || ($year === $endYear && $quarter <= $endQuarter)) {
                    $keys[] = $year . '_' . $quarter;

                    $quarter++;

                    if ($quarter > 4) {
                        $quarter = 1;
                        $year++;
                    }
                }

                break;
        }

        return $keys;
    }

    /**
     * Merge the expected keys into the result, sorting the new group-1 list
     * and synthesising zero-valued rows for any key the engine didn't return.
     *
     * For 1-D reports, each new row gets every column zeroed.
     * For 2-D reports, each new row gets an empty inner map (frontend chart
     * renderers fall back to zero for missing sub-keys), and we top up
     * `group1Sums` so the per-row totals exist too.
     */
    private function mergeExpectedKeys(GridResult $result, array $expectedKeys): GridResult
    {
        $grouping = $result->getGrouping();
        $group1 = $grouping[0] ?? [];

        $mergedGroup1 = array_values(array_unique(array_merge($group1, $expectedKeys)));

        // Sort lexicographically — every supported key format is monotonic
        // under string comparison (Y-m-d, Y-m, Y, Y_Q with single-digit Q).
        sort($mergedGroup1);

        $grouping[0] = $mergedGroup1;
        $result->setGrouping($grouping);

        $columnList = $result->getColumnList();
        $isTwoDim = isset($grouping[1]);

        $reportData = $result->getReportData();

        foreach ($expectedKeys as $key) {
            if (isset($reportData->$key)) {
                continue;
            }

            $reportData->$key = $isTwoDim
                ? (object) []
                : (object) $this->zeroColumnMap($columnList);
        }

        $result->setReportData($reportData);

        if ($isTwoDim) {
            $group1Sums = $result->getGroup1Sums();

            if ($group1Sums !== null) {
                foreach ($expectedKeys as $key) {
                    if (!isset($group1Sums->$key)) {
                        $group1Sums->$key = (object) $this->zeroColumnMap($columnList);
                    }
                }

                $result->setGroup1Sums($group1Sums);
            }
        }

        return $result;
    }

    /**
     * @param string[] $columnList
     * @return array<string, int>
     */
    private function zeroColumnMap(array $columnList): array
    {
        $out = [];

        foreach ($columnList as $col) {
            $out[$col] = 0;
        }

        return $out;
    }
}
