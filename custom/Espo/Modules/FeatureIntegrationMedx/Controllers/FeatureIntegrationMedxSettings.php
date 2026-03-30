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
     * POST FeatureIntegrationMedxSettings/action/replaceCredential
     *
     * Body:
     * {
     *   "id": "profileId",
     *   "newWebCredentialId": "credentialId"
     * }
     */
    public function postActionReplaceCredential(Request $request, Response $response): stdClass
    {
        if (!$this->acl->checkScope('FeatureIntegrationMedxSettings', Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to replace MEDX integration profile credentials.");
        }
        $data = $request->getParsedBody();
        $profileId = $this->extractRequiredString($data->id ?? $data->profileId ?? null, 'id');
        $newWebCredentialId = $this->extractRequiredString($data->newWebCredentialId ?? null, 'newWebCredentialId');
        $profile = $this->entityManager->getEntityById('FeatureIntegrationMedxSettings', $profileId);
        if (!$profile) {
            throw new NotFound('MEDX integration profile not found.');
        }
        if (!$this->acl->check($profile, Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to this MEDX integration profile.");
        }
        return (object) $this->getSettingsService()->replaceCredential(
            $profileId,
            $newWebCredentialId,
        );
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