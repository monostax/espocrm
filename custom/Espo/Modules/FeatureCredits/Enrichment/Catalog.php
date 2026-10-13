<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Accounting\RecordId;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use stdClass;
use Throwable;

/** Global append-only timeline: [effectiveFrom, next effectiveFrom). No mutable active alias. */
final class Catalog
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    public function publish(stdClass $input, string $actorId): array
    {
        $card = new RateCard(get_object_vars($input));
        GrantInput::identity($actorId);
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) throw new LogicException('Enrichment publication owns its transaction.');
        // Replays are valid after activation too, but never accept changed terms.
        if ($row = $this->findVersion($card->version)) return $this->replay($row, $card);
        $pdo->beginTransaction();
        try {
            $this->assertFuture($card);
            $pdo->prepare('INSERT INTO enrichment_credit_catalog
                (id, version, policy_id, effective_from, definition, created_at, created_by_id)
                VALUES (?,?,?,?,?,?,?)')->execute([RecordId::generate(), $card->version, $card->id,
                    $card->effectiveFrom, json_encode($card->definition, JSON_THROW_ON_ERROR), $this->clock->now(), $actorId]);
            // A blocked insert must not retrospectively change the currently selected price.
            $this->assertFuture($card);
            $row = $this->findVersion($card->version);
            $receipt = $this->replay($row, $card);
            $pdo->commit();
            return $receipt;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                // Roll back before reading: PostgreSQL aborts a transaction on unique violation.
                if ($row = $this->findVersion($card->version)) return $this->replay($row, $card);
                $q = $pdo->prepare('SELECT id FROM enrichment_credit_catalog WHERE effective_from=?');
                $q->execute([$card->effectiveFrom]);
                if ($q->fetchColumn()) throw new Conflict('An enrichment catalog already starts at this instant.');
            }
            throw $e;
        }
    }

    public function resolve(string $policyId): RateCard
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $policyId)) throw new InvalidArgumentException('Invalid enrichment catalog reference.');
        $q = $this->entityManager->getPDO()->prepare('SELECT * FROM enrichment_credit_catalog WHERE policy_id=?');
        $q->execute([$policyId]);
        return $this->decode($q->fetch(PDO::FETCH_ASSOC));
    }

    /** For new quotes. Confirmation must reselect; original confirmed work uses resolve. */
    public function current(): RateCard
    {
        $q = $this->entityManager->getPDO()->prepare('SELECT * FROM enrichment_credit_catalog
            WHERE effective_from<=? ORDER BY effective_from DESC LIMIT 1');
        $q->execute([$this->clock->now()]);
        // Do not filter deleted rows: a revoked/corrupt selected version must not fall back.
        return $this->decode($q->fetch(PDO::FETCH_ASSOC));
    }

    private function assertFuture(RateCard $card): void
    {
        if ($card->effectiveFrom <= $this->clock->now()) {
            throw new Conflict('New enrichment catalogs must start in the future.');
        }
    }

    private function findVersion(string $version): array|false
    {
        $q = $this->entityManager->getPDO()->prepare('SELECT * FROM enrichment_credit_catalog WHERE version=?');
        $q->execute([$version]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    private function replay(array|false $row, RateCard $card): array
    {
        if (!$row || $row['deleted'] || $row['policy_id'] !== $card->id) {
            throw new Conflict('Enrichment catalog version already has different terms or is deleted.');
        }
        $saved = $this->decode($row);
        return ['id' => $row['id'], 'policy' => $saved->receipt()];
    }

    private function decode(array|false $row): RateCard
    {
        if (!$row || $row['deleted']) throw new ServiceUnavailable('Approved enrichment catalog is unavailable.');
        try {
            $input = json_decode($row['definition'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($input)) throw new InvalidArgumentException('Invalid catalog definition.');
            $card = new RateCard($input);
        } catch (InvalidArgumentException | \JsonException | \OverflowException | \Brick\Math\Exception\MathException $e) {
            throw new ServiceUnavailable('Approved enrichment catalog is invalid.');
        }
        if (!hash_equals($card->id, $row['policy_id']) || $card->version !== $row['version'] ||
            $card->effectiveFrom !== $row['effective_from']) {
            throw new ServiceUnavailable('Enrichment catalog reference does not match its content.');
        }
        return $card;
    }
}
