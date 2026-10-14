<?php

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDefinition;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateFailure;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateSample;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;
use stdClass;

/** Meta-backed virtual records. All writes use explicit actions, never ORM CRUD. */
class WhatsAppBusinessAccountMessageTemplate
{
    public const ENTITY_TYPE = 'WhatsAppBusinessAccountMessageTemplate';

    public function __construct(
        private EntityManager $entityManager,
        private MetaGraphApiClient $apiClient,
        private TokensProvider $tokensProvider,
        private TemplateAccess $access,
        private TemplateDefinition $validator,
        private TemplateSample $sampleValidator,
    ) {}

    public function find(string $oAuthAccountId, string $businessAccountId, ?TemplateDeadline $deadline = null): RecordCollection
    {
        $deadline ??= new TemplateDeadline();
        $context = $this->access->resolve(compact('oAuthAccountId', 'businessAccountId'), $deadline);
        $list = $this->apiClient->getMessageTemplates($this->token($context, $deadline), $businessAccountId, null, $deadline);
        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        foreach ($list as $data) {
            $collection->append($this->mapTemplateToEntity($data, $context));
        }
        return RecordCollection::create($collection, count($list));
    }

    public function listPage(array $input, TemplateDeadline $deadline): stdClass
    {
        $context = $this->access->resolve($input, $deadline);
        if (isset($input['cursor']) && !is_string($input['cursor'])) {
            throw new BadRequest('Invalid template cursor.');
        }
        $page = $this->apiClient->getMessageTemplatePage($this->token($context, $deadline), $context['businessAccountId'],
            $input['cursor'] ?? null, $deadline);
        $page['list'] = array_map(fn ($data) => $this->mapTemplateToEntity($data, $context)->getValueMap(), $page['list']);
        return (object) $page;
    }

    public function read(string $oAuthAccountId, string $businessAccountId, string $templateId, ?TemplateDeadline $deadline = null): stdClass
    {
        $deadline ??= new TemplateDeadline();
        $context = $this->access->resolve(compact('oAuthAccountId', 'businessAccountId'), $deadline);
        $data = $this->ownedTemplate($context, $this->token($context, $deadline), $templateId, $deadline);
        return $this->mapTemplateToEntity($data, $context)->getValueMap();
    }

    public function mutate(string $action, array $input, ?string $samplePath, TemplateDeadline $deadline): stdClass
    {
        $context = $this->access->resolve($input, $deadline);
        $token = $this->token($context, $deadline);
        $waba = $context['businessAccountId'];
        $current = null;
        if ($action !== 'create') {
            foreach (['templateId', 'name', 'language'] as $field) {
                if (!is_string($input[$field] ?? null) || $input[$field] === '') {
                    throw new BadRequest('Template ID, name and language are required.');
                }
            }
            $current = $this->ownedTemplate($context, $token, $input['templateId'], $deadline);
            if ($current['name'] !== $input['name'] || $current['language'] !== $input['language']) {
                throw new BadRequest('Template identity does not match the selected WABA variant.');
            }
            if (!$this->validator->supported($current)) {
                throw new BadRequest('Unsupported template format is view-only. Use Meta Manager.');
            }
        } else {
            // Prove WABA reachability before uploading bytes. No cross-connection substitution.
            $this->apiClient->getMessageTemplatePage($token, $waba, null, $deadline);
        }
        if ($action === 'delete') {
            if (in_array($current['status'] ?? '', ['DISABLED', 'PENDING_DELETION', 'DELETED'], true)) {
                throw new BadRequest('Disabled or already-deleting templates cannot be deleted here.');
            }
            $result = $this->apiClient->deleteTemplate($token, $waba, $current['id'], $current['name'], $deadline);
        } else {
            if (!is_array($input['definition'] ?? null)) {
                throw new BadRequest('A complete template definition is required.');
            }
            $definition = $this->validator->validate($input['definition']);
            if ($current) {
                if (!in_array($current['status'] ?? '', ['APPROVED', 'REJECTED', 'PAUSED'], true)) {
                    throw new BadRequest('Only approved, rejected or paused templates can be edited.');
                }
                if ($definition['name'] !== $current['name'] || $definition['language'] !== $current['language']) {
                    throw new BadRequest('Template name and language cannot change.');
                }
                if ($current['status'] === 'APPROVED' && $definition['category'] !== $current['category']) {
                    throw new BadRequest('An approved template category cannot change.');
                }
            }
            $format = TemplateDefinition::mediaFormat($definition);
            if ($format) {
                if (!$samplePath) {
                    throw new BadRequest('A fresh sample upload is required for every media submission.');
                }
                $sample = $this->sampleValidator->validate($samplePath, $format);
                $provider = $this->entityManager->getEntityById('OAuthProvider', $context['account']->get('providerId'));
                $handle = $this->apiClient->uploadTemplateSample($token, (string) $provider?->get('clientId'), $sample, $deadline);
                foreach ($definition['components'] as &$component) {
                    if ($component['type'] === 'HEADER') {
                        $component['example'] = ['header_handle' => [$handle]];
                    }
                }
                unset($component);
            } elseif ($samplePath) {
                throw new BadRequest('This definition does not use a media sample.');
            }
            if ($current) {
                // Identity is immutable and is not part of Meta's edit payload.
                $payload = ['components' => $definition['components']];
                if ($current['status'] !== 'APPROVED') {
                    $payload['category'] = $definition['category'];
                }
                $result = $this->apiClient->updateTemplate($token, $current['id'], $payload, $deadline);
            } else {
                $result = $this->apiClient->createTemplate($token, $waba, $definition, $deadline);
            }
        }
        // Refresh is a separate read. Its failure must never turn an acknowledged write into failure.
        return (object) ['accepted' => true, 'templateId' => $result['id'] ?? $current['id'] ?? null,
            'status' => $result['status'] ?? null];
    }

    private function token(array $context, TemplateDeadline $deadline): string
    {
        try {
            $token = $this->tokensProvider->get($context['oAuthAccountId'], $deadline->milliseconds() / 1000)->getAccessToken();
        } catch (\Throwable) {
            throw new TemplateFailure('configuration', 'OAuth token unavailable. Reconnect the selected account.');
        }
        if (!$token) {
            throw new TemplateFailure('configuration', 'OAuth token unavailable. Reconnect the selected account.');
        }
        return $token;
    }

    private function ownedTemplate(array $context, string $token, string $id, TemplateDeadline $deadline): array
    {
        foreach ($this->apiClient->getMessageTemplates($token, $context['businessAccountId'], null, $deadline) as $template) {
            if ((string) ($template['id'] ?? '') === $id) {
                return $template;
            }
        }
        throw new NotFound('Template not found in the selected WABA.');
    }

    private function mapTemplateToEntity(array $data, array $context): Entity
    {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);
        $supported = $this->validator->supported($data);
        $entity->set([
            'id' => $context['oAuthAccountId'] . '_' . $data['id'],
            'templateId' => $data['id'], 'name' => $data['name'] ?? '',
            'language' => $data['language'] ?? '', 'status' => $data['status'] ?? '',
            'category' => $data['category'] ?? '', 'parameterFormat' => $data['parameter_format'] ?? 'POSITIONAL',
            'oAuthAccountId' => $context['oAuthAccountId'], 'oAuthAccountName' => $context['account']->get('name'),
            'businessAccountId' => $context['businessAccountId'],
            'components' => json_encode($data['components'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'supported' => $supported,
            'canEdit' => $supported && in_array($data['status'] ?? '', ['APPROVED', 'REJECTED', 'PAUSED'], true),
            'canDelete' => $supported && !in_array($data['status'] ?? '', ['DISABLED', 'PENDING_DELETION', 'DELETED'], true),
        ]);
        $entity->setAsFetched();
        return $entity;
    }
}
