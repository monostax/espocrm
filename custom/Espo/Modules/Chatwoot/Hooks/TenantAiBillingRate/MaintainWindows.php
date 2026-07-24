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

namespace Espo\Modules\Chatwoot\Hooks\TenantAiBillingRate;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * On create/update of a rate period:
 *  - require effectiveFrom ≤ effectiveTo when both set
 *  - auto-name empty labels as "from → to|∞"
 *  - when saving an open-ended period (effectiveTo null): close prior open
 *    period(s) for the same Tenant on the day before effectiveFrom
 */
class MaintainWindows
{
    public static int $order = 5;

    private const ENTITY_TYPE = 'TenantAiBillingRate';

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!empty($options['silent'])) {
            return;
        }

        $from = $this->asDate($entity->get('effectiveFrom'));
        $to = $this->asDate($entity->get('effectiveTo'));

        if ($from === null) {
            throw new BadRequest('effectiveFrom is required (Y-m-d).');
        }

        if ($to !== null && $to < $from) {
            throw new BadRequest('effectiveTo must be on or after effectiveFrom.');
        }

        $entity->set('effectiveFrom', $from);
        $entity->set('effectiveTo', $to);

        $name = trim((string) ($entity->get('name') ?? ''));

        if ($name === '' || $this->isAutoName($name)) {
            $entity->set('name', $from . ' → ' . ($to ?? '∞'));
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options['silent'])) {
            return;
        }

        // Only open-ended (current) periods auto-close siblings.
        if ($entity->get('effectiveTo') !== null && $entity->get('effectiveTo') !== '') {
            return;
        }

        $tenantId = (string) ($entity->get('tenantId') ?? '');
        $from = $this->asDate($entity->get('effectiveFrom'));

        if ($tenantId === '' || $from === null) {
            return;
        }

        $expireDate = (new \DateTimeImmutable($from))
            ->modify('-1 day')
            ->format('Y-m-d');

        $previous = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where([
                'tenantId' => $tenantId,
                'effectiveTo' => null,
                'id!=' => $entity->getId(),
            ])
            ->find();

        foreach ($previous as $row) {
            $prevFrom = $this->asDate($row->get('effectiveFrom'));

            // Only close open periods that started before the new one.
            if ($prevFrom !== null && $prevFrom >= $from) {
                continue;
            }

            if ($prevFrom !== null && $expireDate < $prevFrom) {
                $row->set('effectiveTo', $prevFrom);
            } else {
                $row->set('effectiveTo', $expireDate);
            }

            $name = trim((string) ($row->get('name') ?? ''));

            if ($name === '' || $this->isAutoName($name)) {
                $row->set(
                    'name',
                    ($prevFrom ?? '?') . ' → ' . (string) $row->get('effectiveTo')
                );
            }

            $this->entityManager->saveEntity($row, ['silent' => true]);
        }
    }

    private function isAutoName(string $name): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2} → (\d{4}-\d{2}-\d{2}|∞)$/u', $name);
    }

    private function asDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $raw = substr((string) $value, 0, 10);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        return $raw;
    }
}
