<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\ORM\EntityManager;

/** Serialize receipt reads with purchases/erasure, without creating an unfunded tenant's wallet. */
final class ReviewLock
{
    public function __construct(private EntityManager $em) {}

    public function run(string $tenant,callable $read): mixed
    {
        GrantInput::identity($tenant); $pdo = $this->em->getPDO();
        if ($pdo->inTransaction()) throw new \LogicException('Purchased review must own its transaction.');
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT id FROM tenant WHERE id=? AND deleted=FALSE FOR UPDATE'); $q->execute([$tenant]);
            if (!$q->fetchColumn()) throw new NotFound('Unknown enrichment tenant.');
            $result = $read($pdo); $pdo->commit(); return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
