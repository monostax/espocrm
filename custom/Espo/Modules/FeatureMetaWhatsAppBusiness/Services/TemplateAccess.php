<?php

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateFailure;
use Espo\ORM\EntityManager;

/** Management delegates to connection READ access, never virtual-record write ACLs. */
class TemplateAccess
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private WhatsAppOAuthHelper $oAuthHelper,
        private CredentialResolver $credentialResolver,
    ) {}

    /** Resolve only after checking Credential and OAuth record access. */
    public function resolve(array $input, ?\Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline $deadline = null): array
    {
        $deadline ??= new \Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline();
        if (!$this->acl->checkScope('WhatsAppBusinessAccount', 'read')) {
            throw new Forbidden('No read access to WhatsAppBusinessAccount.');
        }
        foreach (['credentialId', 'oAuthAccountId', 'businessAccountId', 'wabaId'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) {
                throw new BadRequest('Invalid template context.');
            }
        }
        $credentialId = $input['credentialId'] ?? '';
        $oauthId = $input['oAuthAccountId'] ?? '';
        $wabaId = $input['businessAccountId'] ?? $input['wabaId'] ?? '';
        if (!empty($input['businessAccountId']) && !empty($input['wabaId']) &&
            $input['businessAccountId'] !== $input['wabaId']) {
            throw new BadRequest('Conflicting WABA identifiers.');
        }
        if ($credentialId && $oauthId) {
            throw new BadRequest('Choose Credential or OAuth context, not both.');
        }
        if ($credentialId) {
            $credential = $this->entityManager->getEntityById('Credential', $credentialId);
            if (!$credential) {
                throw new NotFound('Credential not found.');
            }
            if (!$this->acl->check($credential, 'read')) {
                throw new Forbidden('No read access to this Credential.');
            }
            $oauthId = $credential->get('oAuthAccountId');
            if (!$credential->get('isActive') || !$oauthId) {
                throw new BadRequest('Credential requires an active linked OAuth configuration.');
            }
            $this->oAuthHelper->validateTemplateAccountAccess($oauthId);
            try {
                $config = $this->credentialResolver->resolve($credentialId, $deadline->milliseconds() / 1000);
            } catch (\Throwable) {
                throw new TemplateFailure('configuration', 'Could not resolve the linked Credential. Reconnect its OAuth account.');
            }
            $configured = (string) ($config->businessAccountId ?? '');
            if ($wabaId && $wabaId !== $configured) {
                throw new Forbidden('Credential requests are restricted to their configured WABA.');
            }
            $wabaId = $configured;
        }
        if (!$oauthId || !preg_match('/^[0-9]+$/D', $wabaId)) {
            throw new BadRequest('An OAuth account and numeric WABA ID (or a configured Credential) are required.');
        }
        $account = $this->oAuthHelper->validateTemplateAccountAccess($oauthId);
        return ['oAuthAccountId' => $oauthId, 'businessAccountId' => $wabaId, 'account' => $account];
    }
}
