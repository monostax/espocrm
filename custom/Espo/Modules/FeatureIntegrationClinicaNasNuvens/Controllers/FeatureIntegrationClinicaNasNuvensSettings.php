<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\FeatureIntegrationClinicaNasNuvensSettings as SettingsService;
use stdClass;

class FeatureIntegrationClinicaNasNuvensSettings extends \Espo\Core\Templates\Controllers\Base
{
    /**
     * POST FeatureIntegrationClinicaNasNuvensSettings/action/replaceCredential
     *
     * Body:
     * {
     *   "id": "profileId",
     *   "newApiCredentialId": "credentialId",
     *   "newWebCredentialId": "credentialId"
     * }
     */
    public function postActionReplaceCredential(Request $request, Response $response): stdClass
    {
        if (!$this->acl->checkScope('FeatureIntegrationClinicaNasNuvensSettings', Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to replace integration profile credentials.");
        }

        $data = $request->getParsedBody();

        $profileId = $this->extractRequiredString($data->id ?? $data->profileId ?? null, 'id');
        $newApiCredentialId = $this->extractRequiredString($data->newApiCredentialId ?? null, 'newApiCredentialId');
        $newWebCredentialId = $this->extractRequiredString($data->newWebCredentialId ?? null, 'newWebCredentialId');

        $profile = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profileId);

        if (!$profile) {
            throw new NotFound('Integration profile not found.');
        }

        if (!$this->acl->check($profile, Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to this integration profile.");
        }

        return (object) $this->getSettingsService()->replaceCredential(
            $profileId,
            $newApiCredentialId,
            $newWebCredentialId,
        );
    }

    /**
     * POST FeatureIntegrationClinicaNasNuvensSettings/action/requestCnnExport
     *
     * Body: { "id": "profileId" }
     */
    public function postActionRequestCnnExport(Request $request, Response $response): stdClass
    {
        if (!$this->acl->checkScope('FeatureIntegrationClinicaNasNuvensSettings', Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to request CNN export.");
        }

        $data = $request->getParsedBody();

        $profileId = $this->extractRequiredString($data->id ?? null, 'id');

        $profile = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profileId);

        if (!$profile) {
            throw new NotFound('Integration profile not found.');
        }

        if (!$this->acl->check($profile, Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to this integration profile.");
        }

        return (object) $this->getSettingsService()->requestCnnExport($profileId);
    }

    /**
     * POST FeatureIntegrationClinicaNasNuvensSettings/action/downloadCnnExport
     *
     * Body: { "id": "profileId" }
     */
    public function postActionDownloadCnnExport(Request $request, Response $response): stdClass
    {
        if (!$this->acl->checkScope('FeatureIntegrationClinicaNasNuvensSettings', Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to download CNN export.");
        }

        $data = $request->getParsedBody();

        $profileId = $this->extractRequiredString($data->id ?? null, 'id');

        $profile = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profileId);

        if (!$profile) {
            throw new NotFound('Integration profile not found.');
        }

        if (!$this->acl->check($profile, Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to this integration profile.");
        }

        return (object) $this->getSettingsService()->downloadCnnExport($profileId);
    }

    /**
     * POST FeatureIntegrationClinicaNasNuvensSettings/action/importCsvData
     *
     * Body: { "id": "profileId", "dateFrom": "YYYY-MM-DD", "dateTo": "YYYY-MM-DD" }
     */
    public function postActionImportCsvData(Request $request, Response $response): stdClass
    {
        if (!$this->acl->checkScope('FeatureIntegrationClinicaNasNuvensSettings', Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to import CSV data.");
        }

        $data = $request->getParsedBody();

        $profileId = $this->extractRequiredString($data->id ?? null, 'id');
        $dateFrom = $this->extractRequiredString($data->dateFrom ?? null, 'dateFrom');
        $dateTo = $this->extractRequiredString($data->dateTo ?? null, 'dateTo');

        $profile = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profileId);

        if (!$profile) {
            throw new NotFound('Integration profile not found.');
        }

        if (!$this->acl->check($profile, Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to this integration profile.");
        }

        return (object) $this->getSettingsService()->importCsvData($profileId, $dateFrom, $dateTo);
    }

    private function extractRequiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new BadRequest("Missing required parameter: {$field}");
        }

        return trim($value);
    }

    private function getSettingsService(): SettingsService
    {
        return $this->injectableFactory->create(SettingsService::class);
    }
}
