<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

class RebindClinicaNasNuvensAnchorsToCredential implements Job
{
    private const CHUNK_SIZE = 100;

    /**
     * @var array<string, array{remoteField: string, credentialType: 'api'|'web'}>
     */
    private const ANCHOR_ENTITY_MAP = [
        'FeatureIntegrationClinicaNasNuvensAgendamento' => [
            'remoteField' => 'agendamentoId',
            'credentialType' => 'api',
        ],
        'FeatureIntegrationClinicaNasNuvensPaciente' => [
            'remoteField' => 'pacienteId',
            'credentialType' => 'api',
        ],
        'FeatureIntegrationClinicaNasNuvensProfissional' => [
            'remoteField' => 'profissionalId',
            'credentialType' => 'api',
        ],
        'FeatureIntegrationClinicaNasNuvensConvenioTipo' => [
            'remoteField' => 'convenioTipoId',
            'credentialType' => 'api',
        ],
        'FeatureIntegrationClinicaNasNuvensProcedimentoTipo' => [
            'remoteField' => 'procedimentoTipoId',
            'credentialType' => 'api',
        ],
        'FeatureIntegrationClinicaNasNuvensConsultaTipo' => [
            'remoteField' => 'consultaTipoId',
            'credentialType' => 'api',
        ],
        'FeatureIntegrationClinicaNasNuvensFaturamento' => [
            'remoteField' => 'faturamentoId',
            'credentialType' => 'web',
        ],
    ];

    /**
     * @var array<string, array<int, array{entityType: string, foreignKey: string}>>
     */
    private const REFERENCE_MAP = [
        'FeatureIntegrationClinicaNasNuvensAgendamento' => [
            ['entityType' => 'FeatureIntegrationClinicaNasNuvensFaturamento', 'foreignKey' => 'agendamentoId'],
            ['entityType' => 'FeatureIntegrationClinicaNasNuvensAgendamentoProcedimento', 'foreignKey' => 'agendamentoId'],
        ],
        'FeatureIntegrationClinicaNasNuvensPaciente' => [
            ['entityType' => 'FeatureIntegrationClinicaNasNuvensAgendamento', 'foreignKey' => 'pacienteId'],
            ['entityType' => 'FeatureIntegrationClinicaNasNuvensFaturamento', 'foreignKey' => 'pacienteId'],
        ],
        'FeatureIntegrationClinicaNasNuvensProfissional' => [
            ['entityType' => 'FeatureIntegrationClinicaNasNuvensAgendamento', 'foreignKey' => 'profissionalAnchorId'],
            ['entityType' => 'FeatureIntegrationClinicaNasNuvensFaturamento', 'foreignKey' => 'profissionalAnchorId'],
        ],
        'FeatureIntegrationClinicaNasNuvensConvenioTipo' => [
            ['entityType' => 'FeatureIntegrationClinicaNasNuvensAgendamento', 'foreignKey' => 'convenioTipoAnchorId'],
        ],
        'FeatureIntegrationClinicaNasNuvensConsultaTipo' => [
            ['entityType' => 'FeatureIntegrationClinicaNasNuvensAgendamento', 'foreignKey' => 'consultaTipoAnchorId'],
        ],
        'FeatureIntegrationClinicaNasNuvensProcedimentoTipo' => [
            ['entityType' => 'FeatureIntegrationClinicaNasNuvensAgendamentoProcedimento', 'foreignKey' => 'procedimentoTipoId'],
            ['entityType' => 'FeatureIntegrationClinicaNasNuvensProcedimentoConvenio', 'foreignKey' => 'procedimentoTipoId'],
        ],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $profileId = $this->requireString($data, 'profileId');
        $oldApiCredentialId = $this->requireString($data, 'oldApiCredentialId');
        $newApiCredentialId = $this->requireString($data, 'newApiCredentialId');
        $oldWebCredentialId = $this->requireString($data, 'oldWebCredentialId');
        $newWebCredentialId = $this->requireString($data, 'newWebCredentialId');

        $profile = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profileId);

        if (!$profile) {
            $this->log->error("RebindClinicaNasNuvensAnchorsToCredential: profile '{$profileId}' not found.");

            return;
        }

        try {
            foreach (self::ANCHOR_ENTITY_MAP as $entityType => $definition) {
                $oldCredentialId = $definition['credentialType'] === 'api'
                    ? $oldApiCredentialId
                    : $oldWebCredentialId;

                $newCredentialId = $definition['credentialType'] === 'api'
                    ? $newApiCredentialId
                    : $newWebCredentialId;

                $this->rebindEntityType(
                    $entityType,
                    $definition['remoteField'],
                    $oldCredentialId,
                    $newCredentialId,
                );
            }

            $profile->set('migrationStatus', 'completed');
            $this->entityManager->saveEntity($profile, [SaveOption::SKIP_HOOKS => true]);

            $this->deactivateCredential($oldApiCredentialId);
            $this->deactivateCredential($oldWebCredentialId);

            $this->log->info(
                "RebindClinicaNasNuvensAnchorsToCredential: completed profile '{$profileId}'."
            );
        } catch (Throwable $e) {
            $profile->set('migrationStatus', 'failed');
            $this->entityManager->saveEntity($profile, [SaveOption::SKIP_HOOKS => true]);

            $this->log->error(
                "RebindClinicaNasNuvensAnchorsToCredential: failed profile '{$profileId}': " . $e->getMessage()
            );

            throw $e;
        }
    }

    private function rebindEntityType(
        string $entityType,
        string $remoteField,
        string $oldCredentialId,
        string $newCredentialId,
    ): void {
        while (true) {
            $oldCollection = $this->entityManager
                ->getRDBRepository($entityType)
                ->where([
                    'credentialId' => $oldCredentialId,
                    'deleted' => false,
                ])
                ->limit(self::CHUNK_SIZE)
                ->find();

            $anchorList = [];

            foreach ($oldCollection as $anchor) {
                $anchorList[] = $anchor;
            }

            if ($anchorList === []) {
                return;
            }

            foreach ($anchorList as $oldAnchor) {
                $this->rebindAnchor($entityType, $remoteField, $oldAnchor, $newCredentialId);
            }
        }
    }

    private function rebindAnchor(
        string $entityType,
        string $remoteField,
        Entity $oldAnchor,
        string $newCredentialId,
    ): void {
        $oldAnchor = $this->entityManager->getEntityById($entityType, $oldAnchor->getId());

        if (!$oldAnchor || $oldAnchor->get('deleted')) {
            return;
        }

        if ($oldAnchor->get('credentialId') === $newCredentialId) {
            return;
        }

        $remoteId = $this->normalizeNullableString($oldAnchor->get($remoteField));

        if ($remoteId !== null) {
            $duplicateNewAnchor = $this->entityManager
                ->getRDBRepository($entityType)
                ->where([
                    $remoteField => $remoteId,
                    'credentialId' => $newCredentialId,
                    'id!=' => $oldAnchor->getId(),
                    'deleted' => false,
                ])
                ->findOne();

            if ($duplicateNewAnchor) {
                $this->mergeAnchorTeams($oldAnchor, $duplicateNewAnchor);
                $this->rebindReferences($entityType, $oldAnchor->getId(), $duplicateNewAnchor->getId());

                $duplicateNewAnchor->set('deleted', true);
                $this->entityManager->saveEntity($duplicateNewAnchor, [SaveOption::SKIP_ALL => true]);
            }
        }

        $oldAnchor->set('credentialId', $newCredentialId);
        $this->entityManager->saveEntity($oldAnchor, [SaveOption::SKIP_ALL => true]);
    }

    private function mergeAnchorTeams(Entity $target, Entity $source): void
    {
        $targetTeams = $this->normalizeStringList($target->get('teamsIds'));
        $sourceTeams = $this->normalizeStringList($source->get('teamsIds'));

        $merged = array_values(array_unique(array_merge($targetTeams, $sourceTeams)));

        if ($merged !== []) {
            $target->set('teamsIds', $merged);
        }
    }

    private function rebindReferences(string $anchorEntityType, string $targetId, string $sourceId): void
    {
        $referenceMap = self::REFERENCE_MAP[$anchorEntityType] ?? [];

        foreach ($referenceMap as $reference) {
            $collection = $this->entityManager
                ->getRDBRepository($reference['entityType'])
                ->where([
                    $reference['foreignKey'] => $sourceId,
                    'deleted' => false,
                ])
                ->find();

            foreach ($collection as $entity) {
                $entity->set($reference['foreignKey'], $targetId);
                $this->entityManager->saveEntity($entity, [SaveOption::SKIP_ALL => true]);
            }
        }
    }

    private function deactivateCredential(string $credentialId): void
    {
        $credential = $this->entityManager->getEntityById('Credential', $credentialId);

        if (!$credential) {
            return;
        }

        if (!$credential->get('isActive')) {
            return;
        }

        $credential->set('isActive', false);
        $this->entityManager->saveEntity($credential, [SaveOption::SKIP_HOOKS => true]);
    }

    private function requireString(Data $data, string $field): string
    {
        $value = $data->get($field);

        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException("Missing required job data field '{$field}'.");
        }

        return trim($value);
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = trim($item);

            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
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
