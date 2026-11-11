<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use Espo\ORM\EntityManager;

/**
 * Each subject's running record (gtm/05 §12 step 3): what's agreed now and who promised what, by when, every
 * item citing the message behind it. It is updated on demand, in the same analysis as the verdict, from the
 * previous record plus the messages after it. A record is built from the conversations its reader may open
 * and stored per subject and that set (`sourceScope`, gtm/05 §13), so nobody reads a record built from a
 * conversation they can't see.
 */
class Records
{
    /** Messages before the record's edge sent again, so the analysis sees the turn it ended on. */
    private const OVERLAP = 3;

    public function __construct(private EntityManager $em) {}

    /** @param list<string> $conversationIds the CRM conversations the reader may open */
    public static function scope(array $conversationIds): string
    {
        $ids = array_values(array_unique(array_map('strval', $conversationIds)));
        sort($ids);
        return hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
    }

    /** @return ?array{agreed: list<array<string, mixed>>, promises: list<array<string, mixed>>, coveredAt: ?string} */
    public function load(string $type, string $id, string $scope): ?array
    {
        $query = $this->em->getPDO()->prepare('SELECT state, covered_at FROM catch_up_record WHERE deleted = 0 '
            . 'AND subject_type = :type AND subject_id = :id AND source_scope = :scope LIMIT 1');
        $query->execute(['type' => $type, 'id' => $id, 'scope' => $scope]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $state = json_decode((string) $row['state'], true);
        if (!is_array($state)) return null;
        return ['agreed' => array_values($state['agreed'] ?? []), 'promises' => array_values($state['promises'] ?? []), 'coveredAt' => $row['covered_at']];
    }

    /** @param array<string, mixed> $record the analysis's record */
    public function store(string $tenantId, string $type, string $id, string $scope, array $record, ?string $coveredAt): void
    {
        $state = ['agreed' => array_values($record['agreed'] ?? []), 'promises' => array_values($record['promises'] ?? [])];
        $this->em->getPDO()->prepare(
            'INSERT INTO catch_up_record (id, deleted, tenant_id, subject_type, subject_id, source_scope, state, covered_at, due_ts, modified_at) '
            . 'VALUES (:rid, 0, :tenant, :type, :id, :scope, :state, :covered, :due, UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE deleted = 0, state = VALUES(state), covered_at = VALUES(covered_at), due_ts = VALUES(due_ts), '
            . 'modified_at = VALUES(modified_at)'
        )->execute([
            'rid' => substr(hash('sha256', "$type:$id:$scope"), 0, 17), 'tenant' => $tenantId, 'type' => $type, 'id' => $id, 'scope' => $scope,
            'state' => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'covered' => $coveredAt, 'due' => self::due($state),
        ]);
    }

    /**
     * A person marked a card's loop done ("Já resolvi"): the loop is kept in every record of the subject, so the next
     * analysis reads it closed instead of raising it again, and its due time stops counting. A card names its loop by id;
     * cards stored before ids carried one name it by side and text.
     *
     * @param array<string, mixed> $loop the card's `promise`
     * @return bool whether a loop was closed
     */
    public function keep(string $type, string $id, array $loop, string $closedBy): bool
    {
        $pdo = $this->em->getPDO();
        $query = $pdo->prepare('SELECT id, state FROM catch_up_record WHERE deleted = 0 AND subject_type = :type AND subject_id = :id');
        $query->execute(['type' => $type, 'id' => $id]);
        $closed = false;
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $state = json_decode((string) $row['state'], true);
            if (!is_array($state) || !is_array($state['promises'] ?? null)) continue;
            $changed = false;
            foreach ($state['promises'] as $index => $promise) {
                if (!self::names($loop, $promise)) continue;
                $state['promises'][$index] = [...$promise, 'status' => 'kept', 'closedBy' => $closedBy];
                $changed = true;
            }
            if (!$changed) continue;
            $pdo->prepare('UPDATE catch_up_record SET state = :state, due_ts = :due, modified_at = UTC_TIMESTAMP() WHERE id = :rid')->execute([
                'state' => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'due' => self::due($state), 'rid' => $row['id'],
            ]);
            $closed = true;
        }
        return $closed;
    }

    /** Whether a card's loop names this open promise. */
    private static function names(array $loop, mixed $promise): bool
    {
        if (!is_array($promise) || ($promise['status'] ?? 'open') !== 'open' || ($promise['side'] ?? null) !== ($loop['side'] ?? null)) return false;
        if (isset($loop['id']) && $loop['id'] !== '') return (string) ($promise['id'] ?? '') === (string) $loop['id'];
        return isset($loop['text']) && mb_substr((string) ($promise['text'] ?? ''), 0, 200) === (string) $loop['text'];
    }

    /** The earliest due time of an open promise, as a Unix time; code only compares times (the model sets them). */
    public static function due(?array $record): ?int
    {
        $due = null;
        foreach ($record['promises'] ?? [] as $promise) {
            if (($promise['status'] ?? 'open') !== 'open' || empty($promise['dueAt'])) continue;
            try {
                $time = (new DateTimeImmutable((string) $promise['dueAt']))->getTimestamp();
            } catch (\Exception) {
                continue;
            }
            $due = $due === null ? $time : min($due, $time);
        }
        return $due;
    }

    /**
     * What the analysis reads: everything when there is no record yet, otherwise the entries after the
     * record's edge plus a few before it.
     *
     * @param list<array<string, mixed>> $evidence oldest first
     * @return list<array<string, mixed>>
     */
    public static function trim(array $evidence, ?array $record): array
    {
        if (!$record || empty($record['coveredAt'])) return $evidence;
        $edge = strtotime((string) $record['coveredAt']);
        if ($edge === false) return $evidence;
        $first = count($evidence);
        foreach ($evidence as $index => $entry) {
            $at = strtotime((string) ($entry['at'] ?? ''));
            if ($at !== false && $at > $edge) {
                $first = $index;
                break;
            }
        }
        return array_slice($evidence, max(0, $first - self::OVERLAP));
    }

    /** The newest entry's time: where the next record update starts. @param list<array<string, mixed>> $evidence */
    public static function edge(array $evidence): ?string
    {
        $edge = null;
        $edgeTs = null;
        foreach ($evidence as $entry) {
            $at = strtotime((string) ($entry['at'] ?? ''));
            if ($at !== false && ($edgeTs === null || $at > $edgeTs)) [$edge, $edgeTs] = [(string) $entry['at'], $at];
        }
        return $edge;
    }
}
