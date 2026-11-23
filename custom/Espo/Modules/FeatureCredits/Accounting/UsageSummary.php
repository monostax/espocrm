<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Verification\Kind;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/**
 * Provider-neutral credit spend analytics for workspace financial administrators.
 * Aggregates settled unified-prepaid-v1 usage by feature category, local day,
 * unit-economics efficiency (valid WhatsApp / email / phone / contact / AI conversation),
 * and attributed user × feature breakdown.
 */
final class UsageSummary
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    /** @return array<string, mixed> */
    public function inspect(UsageSummaryQuery $input): array
    {
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Usage summary must own its read-only transaction.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Usage summary requires MySQL/PostgreSQL PDO with exception mode.');
        }
        if ($driver === 'mysql') {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }
        $pdo->beginTransaction();
        try {
            if ($driver === 'pgsql') {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $query = $pdo->prepare('SELECT id FROM tenant WHERE id = ? AND deleted = FALSE');
            $query->execute([$input->tenantId]);
            if ($query->fetchColumn() === false) {
                throw new NotFound('Unknown accounting tenant.');
            }

            $now = $this->clock->now();
            $rows = $this->fetchRows($pdo, $input);
            $sourceActors = $this->resolveSourceActors($pdo, $rows);
            $userIds = [];
            foreach ($rows as $row) {
                $actorId = $this->actorId($row, $sourceActors);
                if ($actorId !== null) {
                    $userIds[$actorId] = true;
                }
            }
            $userNames = $this->resolveUserNames($pdo, $driver, array_keys($userIds));
            $pdo->commit();

            return $this->aggregate($input, $now, $rows, $sourceActors, $userNames);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetchRows(PDO $pdo, UsageSummaryQuery $input): array
    {
        $sql = 'SELECT u.id, u.operation_type, u.source_type, u.source_id, u.admitted_at,
                u.settled_credits, u.evidence, u.actor_id,
                pvo.verification_kind, pvo.state AS verification_state,
                pvo.outcome AS verification_outcome, pvo.contact_id AS verification_contact_id,
                pvo.subject_hash AS verification_subject_hash, pvo.actor_id AS verification_actor_id,
                CASE WHEN pvo.id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM phone_verification_request pvr
                    WHERE pvr.operation_id = pvo.id AND pvr.tenant_id = pvo.tenant_id
                      AND pvr.deleted = FALSE AND pvr.request_key LIKE \'automation:%\'
                ) THEN 1 ELSE 0 END AS verification_automated,
                eq.target_type AS enrichment_target_type, eq.target_id AS enrichment_target_id,
                eq.created_by_id AS enrichment_actor_id
            FROM credit_usage u
            LEFT JOIN phone_verification_operation pvo
                ON pvo.usage_id = u.id AND pvo.tenant_id = u.tenant_id AND pvo.deleted = FALSE
            LEFT JOIN enrichment_quote eq
                ON eq.usage_id = u.id AND eq.tenant_id = u.tenant_id AND eq.deleted = FALSE
            WHERE u.tenant_id = ? AND u.deleted = FALSE AND u.billing_regime = ?
              AND u.state = ? AND u.admitted_at >= ? AND u.admitted_at < ?
            ORDER BY u.admitted_at ASC, u.id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $input->tenantId,
            ExecutionRouting::UNIFIED,
            'settled',
            $input->from,
            $input->to,
        ]);

        // Guard against any unexpected duplicate join rows by keying on credit_usage.id.
        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (string) $row['id'];
            if (!isset($byId[$id])) {
                $byId[$id] = $row;
            }
        }
        return array_values($byId);
    }

    /**
     * Resolve user attribution for AI operations whose actor lives on the source record.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, string>>
     */
    private function resolveSourceActors(PDO $pdo, array $rows): array
    {
        $idsByType = ['Note' => [], 'Opportunity' => [], 'Task' => []];
        foreach ($rows as $row) {
            if ($row['operation_type'] !== 'ai' || $row['actor_id'] !== null) {
                continue;
            }
            $type = $row['source_type'];
            $id = $row['source_id'];
            if (is_string($type) && isset($idsByType[$type]) && is_string($id) && $id !== '') {
                $idsByType[$type][$id] = true;
            }
        }

        $tables = [
            'Note' => ['note', 'created_by_id'],
            'Opportunity' => ['opportunity', 'assigned_user_id'],
            'Task' => ['task', 'created_by_id'],
        ];
        $resolved = ['Note' => [], 'Opportunity' => [], 'Task' => []];
        foreach ($tables as $type => [$table, $column]) {
            $ids = array_keys($idsByType[$type]);
            if ($ids === []) {
                continue;
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $pdo->prepare("SELECT id, {$column} AS actor_id FROM {$table} WHERE id IN ({$placeholders})");
                $stmt->execute($chunk);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
                    if (is_string($record['actor_id']) && $record['actor_id'] !== '') {
                        $resolved[$type][(string) $record['id']] = $record['actor_id'];
                    }
                }
            }
        }
        return $resolved;
    }

    /**
     * @param list<string> $userIds
     * @return array<string, string>
     */
    private function resolveUserNames(PDO $pdo, string $driver, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $userTable = $driver === 'pgsql' ? '"user"' : '`user`';
        $names = [];
        foreach (array_chunk($userIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("SELECT id, first_name, last_name, user_name FROM {$userTable} WHERE id IN ({$placeholders})");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $full = trim(((string) ($u['first_name'] ?? '')) . ' ' . ((string) ($u['last_name'] ?? '')));
                $names[(string) $u['id']] = $full !== '' ? $full : (string) ($u['user_name'] ?? $u['id']);
            }
        }
        return $names;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, string>> $sourceActors
     */
    private function actorId(array $row, array $sourceActors): ?string
    {
        if (is_string($row['actor_id']) && $row['actor_id'] !== '') {
            return $row['actor_id'];
        }
        if ($row['operation_type'] === 'verification' && is_string($row['verification_actor_id']) && $row['verification_actor_id'] !== '') {
            return $row['verification_actor_id'];
        }
        if ($row['operation_type'] === 'enrichment' && is_string($row['enrichment_actor_id']) && $row['enrichment_actor_id'] !== '') {
            return $row['enrichment_actor_id'];
        }
        if ($row['operation_type'] === 'ai' && is_string($row['source_type']) && is_string($row['source_id'])) {
            return $sourceActors[$row['source_type']][$row['source_id']] ?? null;
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, array<string, string>> $sourceActors
     * @param array<string, string> $userNames
     * @return array<string, mixed>
     */
    private function aggregate(
        UsageSummaryQuery $input,
        string $now,
        array $rows,
        array $sourceActors,
        array $userNames,
    ): array {
        $zero = BigDecimal::zero()->toScale(4);
        $totalCredits = $zero;
        $totalOps = 0;
        $chargedOps = 0;
        $waivedOps = 0;

        /** @var array<string, array{key: string, group: string, credits: BigDecimal, operations: int, chargedOperations: int, waivedOperations: int}> $categories */
        $categories = [];
        /** @var array<string, array{group: string, credits: BigDecimal, operations: int, chargedOperations: int, waivedOperations: int}> $groups */
        $groups = [];
        /** @var array<string, array{date: string, credits: BigDecimal, operations: int}> $daily */
        $daily = $this->seedDailyBuckets($input);
        /** @var array<string, array{userId: ?string, name: ?string, system: bool, totalCredits: BigDecimal, automatedCredits: BigDecimal, operations: int, categories: array<string, array{key: string, credits: BigDecimal, operations: int}>}> $users */
        $users = [];

        // Unit-economics accumulators.
        $waCredits = $zero;
        $waChecked = 0;
        $validWhatsApps = [];
        $emailCredits = $zero;
        $emailChecked = 0;
        $validEmails = [];
        $phoneCredits = $zero;
        $phoneChecked = 0;
        $validPhones = [];

        $contactEnrichmentCredits = $zero;
        $otherEnrichmentCredits = $zero;
        $verificationCredits = $zero;
        $contactsTouched = [];
        $contactsWithValidWa = [];
        $contactsWithValidEmail = [];
        $contactsWithValidPhone = [];

        $aiConvCredits = $zero;
        $aiConversations = [];

        $utc = new DateTimeZone('UTC');
        $offsetSeconds = $input->tzOffsetMinutes * 60;

        foreach ($rows as $row) {
            $credits = BigDecimal::of((string) ($row['settled_credits'] ?? '0'))->toScale(4, RoundingMode::HALF_UP);
            $isCharged = $credits->isPositive();
            $totalCredits = $totalCredits->plus($credits);
            $totalOps++;
            if ($isCharged) {
                $chargedOps++;
            } else {
                $waivedOps++;
            }

            [$catKey, $groupKey] = $this->classify($row);

            if (!isset($categories[$catKey])) {
                $categories[$catKey] = [
                    'key' => $catKey,
                    'group' => $groupKey,
                    'credits' => $zero,
                    'operations' => 0,
                    'chargedOperations' => 0,
                    'waivedOperations' => 0,
                ];
            }
            $categories[$catKey]['credits'] = $categories[$catKey]['credits']->plus($credits);
            $categories[$catKey]['operations']++;
            if ($isCharged) {
                $categories[$catKey]['chargedOperations']++;
            } else {
                $categories[$catKey]['waivedOperations']++;
            }

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'group' => $groupKey,
                    'credits' => $zero,
                    'operations' => 0,
                    'chargedOperations' => 0,
                    'waivedOperations' => 0,
                ];
            }
            $groups[$groupKey]['credits'] = $groups[$groupKey]['credits']->plus($credits);
            $groups[$groupKey]['operations']++;
            if ($isCharged) {
                $groups[$groupKey]['chargedOperations']++;
            } else {
                $groups[$groupKey]['waivedOperations']++;
            }

            $admittedTs = (new DateTimeImmutable((string) $row['admitted_at'], $utc))->getTimestamp();
            $localDay = gmdate('Y-m-d', $admittedTs + $offsetSeconds);
            if (!isset($daily[$localDay])) {
                $daily[$localDay] = ['date' => $localDay, 'credits' => $zero, 'operations' => 0];
            }
            $daily[$localDay]['credits'] = $daily[$localDay]['credits']->plus($credits);
            $daily[$localDay]['operations']++;

            // User × feature attribution.
            $actorId = $this->actorId($row, $sourceActors);
            $userKey = $actorId ?? '__system__';
            if (!isset($users[$userKey])) {
                $users[$userKey] = [
                    'userId' => $actorId,
                    'name' => $actorId !== null ? ($userNames[$actorId] ?? $actorId) : null,
                    'system' => $actorId === null,
                    'totalCredits' => $zero,
                    'automatedCredits' => $zero,
                    'operations' => 0,
                    'categories' => [],
                ];
            }
            $users[$userKey]['totalCredits'] = $users[$userKey]['totalCredits']->plus($credits);
            $users[$userKey]['operations']++;
            if ((int) ($row['verification_automated'] ?? 0) === 1) {
                $users[$userKey]['automatedCredits'] = $users[$userKey]['automatedCredits']->plus($credits);
            }
            if (!isset($users[$userKey]['categories'][$catKey])) {
                $users[$userKey]['categories'][$catKey] = ['key' => $catKey, 'credits' => $zero, 'operations' => 0];
            }
            $users[$userKey]['categories'][$catKey]['credits'] = $users[$userKey]['categories'][$catKey]['credits']->plus($credits);
            $users[$userKey]['categories'][$catKey]['operations']++;

            // Unit economics.
            $opType = (string) $row['operation_type'];
            if ($opType === 'verification') {
                $verificationCredits = $verificationCredits->plus($credits);
                $kind = $row['verification_kind'] ?? Kind::PHONE;
                $contactId = is_string($row['verification_contact_id']) && $row['verification_contact_id'] !== ''
                    ? $row['verification_contact_id']
                    : (is_string($row['source_id']) && $row['source_id'] !== '' ? $row['source_id'] : null);
                $subjectHash = is_string($row['verification_subject_hash']) ? $row['verification_subject_hash'] : '';
                $completed = $row['verification_state'] === 'completed';
                $outcome = $row['verification_outcome'] ?? null;

                if ($contactId !== null) {
                    $contactsTouched[$contactId] = true;
                }
                $subjectKey = ($contactId ?? '') . ':' . $subjectHash;

                if ($kind === Kind::WHATSAPP) {
                    $waCredits = $waCredits->plus($credits);
                    if ($completed) {
                        $waChecked++;
                        if ($outcome === 'registered') {
                            $validWhatsApps[$subjectKey] = true;
                            if ($contactId !== null) {
                                $contactsWithValidWa[$contactId] = true;
                            }
                        }
                    }
                } elseif ($kind === Kind::EMAIL) {
                    $emailCredits = $emailCredits->plus($credits);
                    if ($completed) {
                        $emailChecked++;
                        if ($outcome === 'deliverable') {
                            $validEmails[$subjectKey] = true;
                            if ($contactId !== null) {
                                $contactsWithValidEmail[$contactId] = true;
                            }
                        }
                    }
                } elseif ($kind === Kind::PHONE) {
                    $phoneCredits = $phoneCredits->plus($credits);
                    if ($completed) {
                        $phoneChecked++;
                        if ($outcome === 'verified') {
                            $validPhones[$subjectKey] = true;
                            if ($contactId !== null) {
                                $contactsWithValidPhone[$contactId] = true;
                            }
                        }
                    }
                }
            } elseif ($opType === 'enrichment') {
                if ($row['enrichment_target_type'] === 'Contact') {
                    $contactEnrichmentCredits = $contactEnrichmentCredits->plus($credits);
                    if (is_string($row['enrichment_target_id']) && $row['enrichment_target_id'] !== '') {
                        $contactsTouched[$row['enrichment_target_id']] = true;
                    }
                } else {
                    $otherEnrichmentCredits = $otherEnrichmentCredits->plus($credits);
                }
            } elseif ($opType === 'ai' && in_array($row['source_type'], ['ChatwootConversation', 'ChatwootAiAgentRun'], true)) {
                $aiConvCredits = $aiConvCredits->plus($credits);
                if (is_string($row['source_id']) && $row['source_id'] !== '') {
                    $aiConversations[$row['source_id']] = true;
                }
            }
        }

        $categoryList = array_values($categories);
        usort($categoryList, static fn ($a, $b) => $b['credits']->compareTo($a['credits']) ?: ($b['operations'] <=> $a['operations']));
        $categoryList = array_map(static fn ($c) => [
            'key' => $c['key'],
            'group' => $c['group'],
            'credits' => (string) $c['credits'],
            'operations' => $c['operations'],
            'chargedOperations' => $c['chargedOperations'],
            'waivedOperations' => $c['waivedOperations'],
            'share' => self::ratio($c['credits'], $totalCredits),
        ], $categoryList);

        $groupList = array_values($groups);
        usort($groupList, static fn ($a, $b) => $b['credits']->compareTo($a['credits']) ?: ($b['operations'] <=> $a['operations']));
        $groupList = array_map(static fn ($g) => [
            'group' => $g['group'],
            'credits' => (string) $g['credits'],
            'operations' => $g['operations'],
            'chargedOperations' => $g['chargedOperations'],
            'waivedOperations' => $g['waivedOperations'],
            'share' => self::ratio($g['credits'], $totalCredits),
        ], $groupList);

        ksort($daily);
        $dailyList = array_values(array_map(static fn ($d) => [
            'date' => $d['date'],
            'credits' => (string) $d['credits'],
            'operations' => $d['operations'],
        ], $daily));

        $userList = array_values($users);
        usort($userList, static fn ($a, $b) => $b['totalCredits']->compareTo($a['totalCredits']) ?: ($b['operations'] <=> $a['operations']));
        $userList = array_map(static function ($u) use ($totalCredits): array {
            $cats = array_values($u['categories']);
            usort($cats, static fn ($a, $b) => $b['credits']->compareTo($a['credits']) ?: ($b['operations'] <=> $a['operations']));
            return [
                'userId' => $u['userId'],
                'name' => $u['name'],
                'system' => $u['system'],
                'totalCredits' => (string) $u['totalCredits'],
                'automatedCredits' => (string) $u['automatedCredits'],
                'operations' => $u['operations'],
                'share' => self::ratio($u['totalCredits'], $totalCredits),
                'categories' => array_map(static fn ($c) => [
                    'key' => $c['key'],
                    'credits' => (string) $c['credits'],
                    'operations' => $c['operations'],
                ], $cats),
            ];
        }, $userList);

        $validContacts = count(array_intersect_key($contactsWithValidWa, $contactsWithValidEmail));
        $fullyVerifiedContacts = count(array_intersect_key($contactsWithValidWa, $contactsWithValidEmail, $contactsWithValidPhone));
        $contactPipelineCredits = $contactEnrichmentCredits->plus($verificationCredits);
        $validWaCount = count($validWhatsApps);
        $validEmailCount = count($validEmails);
        $validPhoneCount = count($validPhones);
        $aiConvCount = count($aiConversations);

        return [
            'tenantId' => $input->tenantId,
            'observedAt' => $now,
            'range' => [
                'from' => $input->from,
                'to' => $input->to,
                'tzOffsetMinutes' => $input->tzOffsetMinutes,
            ],
            'totals' => [
                'credits' => (string) $totalCredits,
                'operations' => $totalOps,
                'chargedOperations' => $chargedOps,
                'waivedOperations' => $waivedOps,
                'dailyAverageCredits' => self::divide($totalCredits, max(1, count($dailyList))),
            ],
            'groups' => $groupList,
            'categories' => $categoryList,
            'daily' => $dailyList,
            'efficiency' => [
                'validWhatsApps' => $validWaCount,
                'checkedWhatsApps' => $waChecked,
                'whatsappCredits' => (string) $waCredits,
                'avgCostPerValidWhatsApp' => self::divide($waCredits, $validWaCount),
                'whatsappHitRate' => self::intRatio($validWaCount, $waChecked),
                'validEmails' => $validEmailCount,
                'checkedEmails' => $emailChecked,
                'emailCredits' => (string) $emailCredits,
                'avgCostPerValidEmail' => self::divide($emailCredits, $validEmailCount),
                'emailHitRate' => self::intRatio($validEmailCount, $emailChecked),
                'validPhones' => $validPhoneCount,
                'checkedPhones' => $phoneChecked,
                'phoneCredits' => (string) $phoneCredits,
                'avgCostPerValidPhone' => self::divide($phoneCredits, $validPhoneCount),
                'phoneHitRate' => self::intRatio($validPhoneCount, $phoneChecked),
                'contactsTouched' => count($contactsTouched),
                'contactsWithValidWhatsApp' => count($contactsWithValidWa),
                'contactsWithValidEmail' => count($contactsWithValidEmail),
                'validContacts' => $validContacts,
                'fullyVerifiedContacts' => $fullyVerifiedContacts,
                'contactEnrichmentCredits' => (string) $contactEnrichmentCredits,
                'otherEnrichmentCredits' => (string) $otherEnrichmentCredits,
                'verificationCredits' => (string) $verificationCredits,
                'contactPipelineCredits' => (string) $contactPipelineCredits,
                'avgCostPerValidContact' => self::divide($contactPipelineCredits, $validContacts),
                'avgCostPerFullyVerifiedContact' => self::divide($contactPipelineCredits, $fullyVerifiedContacts),
                'aiConversations' => $aiConvCount,
                'aiConversationCredits' => (string) $aiConvCredits,
                'avgCostPerAiConversation' => self::divide($aiConvCredits, $aiConvCount),
            ],
            'byUser' => $userList,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: string, 1: string}
     */
    private function classify(array $row): array
    {
        $opType = (string) $row['operation_type'];
        if ($opType === 'enrichment') {
            return ['ENRICHMENT', 'enrichment'];
        }
        if ($opType === 'apollo') {
            return ['PROSPECTING', 'enrichment'];
        }
        if ($opType === 'messaging') {
            return ['WHATSAPP_NOTIFICATION', 'messaging'];
        }
        if ($opType === 'verification') {
            $kind = $row['verification_kind'] ?? Kind::PHONE;
            if ($kind === Kind::EMAIL) {
                return ['VERIFY_EMAIL', 'verification'];
            }
            if ($kind === Kind::WHATSAPP) {
                return ['VERIFY_WHATSAPP', 'verification'];
            }
            return ['VERIFY_PHONE', 'verification'];
        }

        $sourceRequest = $this->hasSourceRequest($row['evidence'] ?? null);
        return match ($row['source_type'] ?? null) {
            'ChatwootConversation', 'ChatwootAiAgentRun' => [
                $sourceRequest ? 'EM_DIA_CONVERSATION' : 'AGENT_REPLY',
                'ai',
            ],
            'Opportunity' => [
                $sourceRequest ? 'EM_DIA_DEAL' : 'FOLLOW_UP',
                'ai',
            ],
            'Note' => ['MURAL', 'ai'],
            'Task' => ['TASK', 'ai'],
            'ChatwootAccountUserMembership' => ['AGENT_TEST', 'ai'],
            default => ['AI', 'ai'],
        };
    }

    private function hasSourceRequest(mixed $evidence): bool
    {
        if (!is_string($evidence) || $evidence === '') {
            return false;
        }
        $decoded = json_decode($evidence);
        $request = is_object($decoded) ? ($decoded->sourceRequestId ?? null) : null;
        return is_string($request) && preg_match('/^[a-f0-9]{64}$/D', $request) === 1;
    }

    /** @return array<string, array{date: string, credits: BigDecimal, operations: int}> */
    private function seedDailyBuckets(UsageSummaryQuery $input): array
    {
        $utc = new DateTimeZone('UTC');
        $offsetSeconds = $input->tzOffsetMinutes * 60;
        $startTs = (new DateTimeImmutable($input->from, $utc))->getTimestamp() + $offsetSeconds;
        $endTs = (new DateTimeImmutable($input->to, $utc))->getTimestamp() + $offsetSeconds - 1;
        if ($endTs < $startTs) {
            return [];
        }
        $cursor = new DateTimeImmutable(gmdate('Y-m-d', $startTs) . ' 00:00:00', $utc);
        $lastDay = gmdate('Y-m-d', $endTs);
        $zero = BigDecimal::zero()->toScale(4);
        $buckets = [];
        for ($i = 0; $i <= UsageSummaryQuery::MAX_RANGE_DAYS; $i++) {
            $day = $cursor->format('Y-m-d');
            $buckets[$day] = ['date' => $day, 'credits' => $zero, 'operations' => 0];
            if ($day >= $lastDay) {
                break;
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $buckets;
    }

    private static function divide(BigDecimal $numerator, int $denominator): ?string
    {
        if ($denominator <= 0) {
            return null;
        }
        return (string) $numerator->dividedBy($denominator, 4, RoundingMode::HALF_UP);
    }

    private static function ratio(BigDecimal $part, BigDecimal $whole): float
    {
        if (!$whole->isPositive()) {
            return 0.0;
        }
        return round($part->dividedBy($whole, 6, RoundingMode::HALF_UP)->toFloat(), 4);
    }

    private static function intRatio(int $part, int $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }
        return round($part / $whole, 4);
    }
}
