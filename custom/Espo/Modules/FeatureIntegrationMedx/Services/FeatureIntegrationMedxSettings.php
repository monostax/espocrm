<?php
namespace Espo\Modules\FeatureIntegrationMedx\Services;
use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\Service as RecordService;
use Espo\ORM\Entity;
/**
 * @extends RecordService<Entity>
 */
class FeatureIntegrationMedxSettings extends RecordService
{
    private const WEB_CREDENTIAL_TYPE_CODE = 'medx-web';
    /**
     * @return array{status: string, profileId: string, message: string}
     */
    public function replaceCredential(
        string $profileId,
        string $newWebCredentialId,
    ): array {
        if (!$this->acl->checkScope('FeatureIntegrationMedxSettings', Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to replace MEDX profile credentials.");
        }
        $profile = $this->entityManager->getEntityById('FeatureIntegrationMedxSettings', $profileId);
        if (!$profile) {
            throw new NotFound('MEDX integration profile not found.');
        }
        if (!$this->acl->check($profile, Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to edit this MEDX integration profile.");
        }
        $oldWebCredentialId = $this->normalizeNullableString($profile->get('webCredentialId'));
        if (!$oldWebCredentialId) {
            throw new BadRequest('Profile is missing currently linked Web credential.');
        }
        if ($newWebCredentialId === $oldWebCredentialId) {
            throw new BadRequest('New Web credential must differ from current profile Web credential.');
        }
        $newWebCredential = $this->assertCredentialReadableAndActive($newWebCredentialId);
        $this->assertWebCredentialType($newWebCredential);
        $profile->set('webCredentialId', $newWebCredentialId);
        $this->entityManager->saveEntity($profile);
        return [
            'status' => 'replaced',
            'profileId' => $profileId,
            'message' => 'Web credential has been replaced successfully.',
        ];
    }
    private function assertCredentialReadableAndActive(string $credentialId): Entity
    {
        $credential = $this->entityManager->getEntityById('Credential', $credentialId);
        if (!$credential) {
            throw new NotFound("Credential '{$credentialId}' not found.");
        }
        if (!$this->acl->check($credential, Acl\Table::ACTION_READ)) {
            throw new Forbidden("You don't have access to the selected credential.");
        }
        if (!$credential->get('isActive')) {
            throw new BadRequest("Credential '{$credentialId}' is inactive.");
        }
        return $credential;
    }
    private function assertWebCredentialType(Entity $credential): void
    {
        $credentialTypeCode = $this->resolveCredentialTypeCode($credential);
        if ($credentialTypeCode !== self::WEB_CREDENTIAL_TYPE_CODE) {
            throw new BadRequest('Web credential must use MEDX Web credential type (medx-web).');
        }
    }
    private function resolveCredentialTypeCode(Entity $credential): string
    {
        $credentialTypeId = $this->normalizeNullableString($credential->get('credentialTypeId'));
        if (!$credentialTypeId) {
            throw new BadRequest('Credential has no credential type assigned.');
        }
        $credentialType = $this->entityManager->getEntityById('CredentialType', $credentialTypeId);
        $code = $this->normalizeNullableString($credentialType?->get('code'));
        if (!$code) {
            throw new BadRequest('Credential type is missing code metadata.');
        }
        return $code;
    }
    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $normalized = trim($value);
        return $normalized !== '' ? $normalized : null;
    }
}