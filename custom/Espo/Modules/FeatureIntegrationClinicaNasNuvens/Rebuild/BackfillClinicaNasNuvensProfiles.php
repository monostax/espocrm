<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Rebuild;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class BackfillClinicaNasNuvensProfiles implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $profiles = $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensSettings')
            ->find();

        $updated = 0;

        foreach ($profiles as $profile) {
            if ($this->backfillProfile($profile)) {
                $updated++;
            }
        }

        $this->log->info("BackfillClinicaNasNuvensProfiles: completed, updated {$updated} profile(s).");
    }

    private function backfillProfile(Entity $profile): bool
    {
        $changed = false;
        $name = $this->normalizeNullableString($profile->get('name'));

        if ($name === null) {
            $tenantName = $this->resolveTenantName($profile->get('tenantId'));
            $profile->set('name', $tenantName ? "{$tenantName} - CNN Profile" : 'Clinica Nas Nuvens Profile');
            $changed = true;
        }

        if ($profile->get('isActive') === null) {
            $profile->set('isActive', true);
            $changed = true;
        }

        $migrationStatus = $this->normalizeNullableString($profile->get('migrationStatus'));

        if ($migrationStatus === null) {
            $profile->set('migrationStatus', 'idle');
            $changed = true;
        }

        $teamId = $this->resolveSingleScopeTeamId($profile);

        if ($teamId !== null) {
            $current = $this->normalizeTeamIds($profile->get('teamsIds'));

            if ($current !== [$teamId]) {
                $profile->set('teamsIds', [$teamId]);
                $changed = true;
            }
        }

        if (!$changed) {
            return false;
        }

        $this->entityManager->saveEntity($profile, [SaveOption::SKIP_HOOKS => true]);

        return true;
    }

    private function resolveSingleScopeTeamId(Entity $profile): ?string
    {
        $teamIds = $this->normalizeTeamIds($profile->get('teamsIds'));

        if ($teamIds !== []) {
            return $teamIds[0];
        }

        $tenant = $this->entityManager->getEntityById('Tenant', $profile->get('tenantId'));

        return $this->normalizeNullableString($tenant?->get('baseUserTeamId'));
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function normalizeTeamIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $id) {
            $id = $this->normalizeNullableString($id);

            if ($id !== null) {
                $normalized[] = $id;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function resolveTenantName(mixed $tenantId): ?string
    {
        $tenantId = $this->normalizeNullableString($tenantId);

        if ($tenantId === null) {
            return null;
        }

        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);

        return $this->normalizeNullableString($tenant?->get('name'));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
