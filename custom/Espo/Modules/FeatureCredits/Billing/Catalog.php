<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Espo\Core\Exceptions\{Conflict, ServiceUnavailable};
use Espo\Modules\FeatureCredits\Accounting\{Clock, GrantInput, RecordId};
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use stdClass;
use Throwable;

/** Append-only catalog timeline plus immutable product/offer versions shared across publications. */
final class Catalog
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    public function publish(stdClass $input, string $actorId): array
    {
        $catalog = new CatalogDefinition($input);
        GrantInput::identity($actorId);
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) throw new LogicException('Billing catalog publication owns its transaction.');
        if ($row = $this->findVersion($catalog->version)) return $this->replay($row, $catalog);
        $pdo->beginTransaction();
        try {
            $this->assertFuture($catalog);
            $pdo->prepare('INSERT INTO credit_billing_catalog
                (id, version, catalog_id, effective_from, definition, created_at, created_by_id)
                VALUES (?,?,?,?,?,?,?)')->execute([RecordId::generate(), $catalog->version, $catalog->id,
                    $catalog->effectiveFrom, json_encode($catalog->definition, JSON_THROW_ON_ERROR), $this->clock->now(), $actorId]);
            foreach ($catalog->products as $product) {
                $this->retainVersion('credit_billing_product', $product['productId'], $product['productVersion'],
                    CatalogDefinition::productHash($product), $product, $actorId);
            }
            foreach ($catalog->offers as $terms) {
                $this->retainVersion('credit_market_offer', $terms->offerId, $terms->version,
                    $terms->termsId, $terms->jsonSerialize(), $actorId);
            }
            $receipt = $this->replay($this->findVersion($catalog->version), $catalog);
            // Include validation reads as well as lock waits in the prospective-publication fence.
            $this->assertFuture($catalog);
            $pdo->commit();
            return $receipt;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                if ($row = $this->findVersion($catalog->version)) return $this->replay($row, $catalog);
                $q = $pdo->prepare('SELECT id FROM credit_billing_catalog WHERE effective_from=?');
                $q->execute([$catalog->effectiveFrom]);
                if ($q->fetchColumn()) throw new Conflict('A billing catalog already starts at this instant.');
            }
            throw $e;
        }
    }

    public function current(): CatalogDefinition
    {
        return $this->selectCurrent(false);
    }

    /** Checkout uses a current locking read, including on MySQL REPEATABLE READ. */
    public function currentForAcceptance(): CatalogDefinition
    {
        if (!$this->entityManager->getPDO()->inTransaction()) throw new LogicException('Acceptance requires its tenant transaction.');
        try {
            return $this->selectCurrent(true);
        } catch (PDOException $e) {
            // MariaDB snapshot isolation rejects a locking read if an earlier
            // snapshot predates a newly published catalog. Abort acceptance;
            // a fresh command can review the new terms instead of using stale ones.
            if ((int) ($e->errorInfo[1] ?? 0) === 1020 && $this->entityManager->getPDO()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                throw new Conflict('Billing catalog changed during acceptance; review current terms.');
            }
            throw $e;
        }
    }

    private function selectCurrent(bool $lock): CatalogDefinition
    {
        $pdo = $this->entityManager->getPDO();
        // LOCK IN SHARE MODE is supported by both MySQL and MariaDB; MariaDB
        // does not accept MySQL 8's FOR SHARE spelling. PostgreSQL requires it.
        $locking = $this->sharedLockClause();
        $q = $pdo->prepare('SELECT * FROM credit_billing_catalog
            WHERE effective_from<=? ORDER BY effective_from DESC LIMIT 1' . ($lock ? $locking : ''));
        $q->execute([$this->clock->now()]);
        // Deleted/corrupt selected versions cannot silently restore older sale offers.
        return $this->decode($q->fetch(PDO::FETCH_ASSOC), $lock);
    }

    public function resolve(string $catalogId): CatalogDefinition
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $catalogId)) throw new InvalidArgumentException('Invalid billing catalog reference.');
        $q = $this->entityManager->getPDO()->prepare('SELECT * FROM credit_billing_catalog WHERE catalog_id=?');
        $q->execute([$catalogId]);
        return $this->decode($q->fetch(PDO::FETCH_ASSOC));
    }

    /** Fixed private table names only. Insert-or-retain locks the same logical version on both dialects. */
    private function retainVersion(string $table, string $key, string $version, string $hash, array $definition, string $actor): void
    {
        $pdo = $this->entityManager->getPDO();
        $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ?
            ' ON DUPLICATE KEY UPDATE id=id' : ' ON CONFLICT (commercial_key, version) DO NOTHING';
        $pdo->prepare("INSERT INTO $table (id, commercial_key, version, terms_id, definition, created_at, created_by_id)
            VALUES (?,?,?,?,?,?,?)" . $suffix)->execute([RecordId::generate(), $key, $version, $hash,
                json_encode($definition, JSON_THROW_ON_ERROR), $this->clock->now(), $actor]);
        $q = $pdo->prepare("SELECT * FROM $table WHERE commercial_key=? AND version=? FOR UPDATE");
        $q->execute([$key, $version]);
        $this->assertVersion($q->fetch(PDO::FETCH_ASSOC), $key, $version, $hash, $definition, true);
    }

    private function assertVersion(array|false $row, string $key, string $version, string $hash, array $definition, bool $publishing): void
    {
        $valid = $row && !$row['deleted'] && $row['commercial_key'] === $key && $row['version'] === $version && $row['terms_id'] === $hash;
        if ($valid) {
            try {
                $stored = json_decode($row['definition'], false, 32, JSON_THROW_ON_ERROR);
                // Canonical objects account for database JSON key ordering without allowing type coercion.
                $expected = json_decode(json_encode($definition, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
                $valid = json_encode(GrantInput::canonical($stored), JSON_THROW_ON_ERROR) ===
                    json_encode(GrantInput::canonical($expected), JSON_THROW_ON_ERROR);
            } catch (\JsonException | InvalidArgumentException) { $valid = false; }
        }
        if (!$valid) {
            if ($publishing) throw new Conflict('Commercial version already has different terms or is unavailable.');
            throw new ServiceUnavailable('Stored commercial version is unavailable or inconsistent.');
        }
    }

    private function assertFuture(CatalogDefinition $catalog): void
    {
        if ($catalog->effectiveFrom <= $this->clock->now()) throw new Conflict('New billing catalogs must start in the future.');
    }

    private function findVersion(string $version): array|false
    {
        $q = $this->entityManager->getPDO()->prepare('SELECT * FROM credit_billing_catalog WHERE version=?');
        $q->execute([$version]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    private function replay(array|false $row, CatalogDefinition $catalog): array
    {
        if (!$row || $row['deleted'] || $row['catalog_id'] !== $catalog->id) {
            throw new Conflict('Billing catalog version already has different terms or is deleted.');
        }
        return ['id' => $row['id'], 'catalog' => $this->decode($row)->receipt()];
    }

    private function decode(array|false $row, bool $lock = false): CatalogDefinition
    {
        if (!$row || $row['deleted']) throw new ServiceUnavailable('Approved billing catalog is unavailable.');
        try {
            $input = json_decode($row['definition'], false, 32, JSON_THROW_ON_ERROR);
            if (!$input instanceof stdClass) throw new InvalidArgumentException('Invalid catalog definition.');
            $catalog = new CatalogDefinition($input);
        } catch (InvalidArgumentException | \JsonException | \OverflowException | \Brick\Math\Exception\MathException) {
            throw new ServiceUnavailable('Approved billing catalog is invalid.');
        }
        if ($row['catalog_id'] !== $catalog->id || $row['version'] !== $catalog->version || $row['effective_from'] !== $catalog->effectiveFrom) {
            throw new ServiceUnavailable('Billing catalog reference does not match its content.');
        }
        foreach (['credit_billing_product' => $catalog->products, 'credit_market_offer' => $catalog->offers] as $table => $items) {
            $q = $this->entityManager->getPDO()->prepare("SELECT * FROM $table WHERE commercial_key=? AND version=?" . ($lock ? $this->sharedLockClause() : ''));
            foreach ($items as $item) {
                $offer = $item instanceof OfferTerms;
                $key = $offer ? $item->offerId : $item['productId'];
                $version = $offer ? $item->version : $item['productVersion'];
                $q->execute([$key, $version]);
                $this->assertVersion($q->fetch(PDO::FETCH_ASSOC), $key, $version,
                    $offer ? $item->termsId : CatalogDefinition::productHash($item),
                    $offer ? $item->jsonSerialize() : $item, false);
            }
        }
        return $catalog;
    }

    private function sharedLockClause(): string
    {
        return $this->entityManager->getPDO()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : ' FOR SHARE';
    }
}
