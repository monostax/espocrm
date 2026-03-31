<?php

namespace Espo\Modules\FeatureIntegrationMedx\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureIntegrationMedx\Services\FeatureIntegrationMedxSettings as SettingsService;
use stdClass;

class FeatureIntegrationMedxSettings extends \Espo\Core\Templates\Controllers\Base
{
    /**
     * POST /api/v1/FeatureIntegrationMedxSettings/action/replaceCredential
     *
     * Body:
     * {
     *   "id": "profileId",
     *   "newWebCredentialId": "credentialId",
     *   "reason": "optional explanation"
     * }
     */
    public function postActionReplaceCredential(Request $request, Response $response): stdClass
    {
        if (!$this->acl->checkScope('FeatureIntegrationMedxSettings', Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to replace MEDX integration profile credentials.");
        }

        $data     = $request->getParsedBody();
        $profileId          = $this->extractRequiredString($data->id ?? $data->profileId ?? null, 'id');
        $newCredentialId    = $this->extractRequiredString($data->newWebCredentialId ?? null, 'newWebCredentialId');

        $reason = null;

        if (isset($data->reason) && is_string($data->reason) && trim($data->reason) !== '') {
            $reason = trim($data->reason);
        }

        $profile = $this->entityManager->getEntityById('FeatureIntegrationMedxSettings', $profileId);

        if (!$profile) {
            throw new NotFound('MEDX integration profile not found.');
        }

        if (!$this->acl->check($profile, Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to edit this MEDX integration profile.");
        }

        return (object) $this->getSettingsService()->replaceCredential(
            $profileId,
            $newCredentialId,
            $reason,
        );
    }

    /**
     * POST /api/v1/FeatureIntegrationMedxSettings/action/importClientes
     *
     * Body (all optional):
     * {
     *   "id":       "profileId",          // Specific profile; omit for all active
     *   "top":      10000,               // Max rows per page
     *   "skip":     0,                   // Offset for pagination
     *   "paginate": true                  // If true, automatically handles full pagination
     * }
     */
    public function postActionImportClientes(Request $request, Response $response): stdClass
    {
        if (!$this->acl->checkScope('FeatureIntegrationMedxSettings', Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to trigger MEDX cliente imports.");
        }

        $body = $request->getParsedBody();

        $profileId = null;
        $top       = null;
        $skip      = null;

        if (isset($body->id) && is_string($body->id) && trim($body->id) !== '') {
            $profileId = trim($body->id);
        } elseif (isset($body->profileId) && is_string($body->profileId) && trim($body->profileId) !== '') {
            $profileId = trim($body->profileId);
        }

        if (isset($body->top) && is_numeric($body->top)) {
            $top = (int) $body->top;
        }

        if (isset($body->skip) && is_numeric($body->skip)) {
            $skip = (int) $body->skip;
        }

        return (object) $this->getSettingsService()->importClientes($profileId, [
            'top'  => $top,
            'skip' => $skip,
        ]);
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
