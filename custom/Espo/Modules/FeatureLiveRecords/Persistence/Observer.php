<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Persistence;

use Closure;
use DateTimeImmutable;
use Espo\Core\Exceptions\Conflict;
use Espo\ORM\BaseEntity;
use Espo\ORM\Exception\ConcurrencyConflict;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\MutationObserver;
use Espo\Modules\FeatureLiveRecords\Outbox\Intent;
use Espo\Modules\FeatureLiveRecords\Outbox\Store;
use Espo\Modules\FeatureLiveRecords\Scope;

final class Observer implements MutationObserver
{
    public function __construct(private EntityManager $em, private Routing $routing) {}

    public function run(array $entities, string $operation, Closure $persist): mixed
    {
        try {
            return $this->em->getTransactionManager()->run(function () use ($entities, $operation, $persist) {
                $related = [];

                if (in_array($operation, ['link', 'unlink'], true)) {
                    foreach ($entities as $entity) {
                        if ($entity instanceof BaseEntity && $entity->isBeingSaved()) {
                            continue;
                        }
                        if (
                            !$this->em->getMetadata()->get($entity->getEntityType(), 'versionAttribute') &&
                            !$this->em->getMetadata()->get($entity->getEntityType(), 'concurrencyAttribute')
                        ) {
                            continue;
                        }

                        $related[$entity->getEntityType() . ':' . $entity->getId()] = $entity;
                    }

                    ksort($related);

                    foreach ($related as $entity) {
                        $this->em->getRDBRepository($entity->getEntityType())
                            ->select(['id', 'versionNumber'])
                            ->where(['id' => $entity->getId()])
                            ->forUpdate()
                            ->findOne();
                    }
                }

                $valuesBefore = $operation === 'update'
                    ? array_map(fn ($entity) => ChangePolicy::snapshot($this->em->getPDO(), $entity), $entities)
                    : null;
                $before = $this->routing->snapshot($entities);
                $result = $persist();

                if (in_array($operation, ['link', 'unlink'], true) && $result === false) {
                    return $result;
                }

                if ($valuesBefore !== null && $valuesBefore === array_map(
                    fn ($entity) => ChangePolicy::snapshot($this->em->getPDO(), $entity), $entities,
                )) {
                    return $result;
                }

                foreach ($related as $entity) {
                    $current = $this->em->getEntityById($entity->getEntityType(), $entity->getId());
                    if (!$current) {
                        continue;
                    }

                    $query = $this->em->getQueryBuilder()
                        ->update()
                        ->in($entity->getEntityType())
                        ->where(['id' => $entity->getId()])
                        ->set(['versionNumber' => (int) $current->get('versionNumber') + 1])
                        ->build();

                    $this->em->getQueryExecutor()->execute($query);
                }

                $after = $operation === 'delete' ? [] : $this->routing->snapshot($entities);
                $store = new Store($this->em->getPDO());
                $accessEntities = array_filter($entities, fn ($entity) => in_array(
                    $entity->getEntityType(),
                    Scope::ACCESS,
                    true,
                ));
                // Linking records to or from a team changes who can see those
                // records only. Keep it record-scoped, so viewers holding them
                // re-check (and are revoked) without resetting the whole tenant.
                // Team-to-user/role/membership links stay full access resets.
                $recordTeamLink = in_array($operation, ['link', 'unlink'], true) &&
                    count($accessEntities) < count($entities) &&
                    !array_filter($accessEntities, fn ($entity) => $entity->getEntityType() !== 'Team');
                $accessChange = $accessEntities && !$recordTeamLink;

                foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $tenantId) {
                    $records = array_values(array_merge($before[$tenantId] ?? [], $after[$tenantId] ?? []));
                    if ($recordTeamLink) {
                        $records = array_values(array_filter($records, fn ($record) => $record['type'] !== 'Team'));
                    }
                    // Policy references (e.g. an audit Note for a Role) carry
                    // reset-only routing, with no tenant-local record identity.
                    // A team whose tenant holds none of the linked records resets.
                    $reset = $accessChange || $records === [];
                    $intent = new Intent($tenantId, $reset ? 'access' : $operation, $reset ? [] : $records);
                    $store->append($intent, new DateTimeImmutable());
                }

                return $result;
            });
        } catch (ConcurrencyConflict) {
            throw new Conflict('Record changed or removed. Read the latest version before saving.');
        }
    }
}
