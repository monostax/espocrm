<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Chatwoot\Tools\WhatsAppChannel;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\TemplateSendPreflight;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDefinition;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateParameters;
use Espo\Modules\Global\Tools\CustomField\MetaProvider;
use Espo\ORM\Entity;

/** Only explicitly permitted CRM scalar fields are exposed to the composer. */
class ManagedCampaignPayload
{
    public function __construct(
        private Metadata $metadata, private MetaProvider $fields, private AclManager $acl,
        private ChatwootApiClient $api, private TemplateSendPreflight $preflight, private ManagedCampaignAccess $access,
    ) {}

    public function variables(array $ctx): array
    {
        $result = [];
        foreach ((array) $this->metadata->get(['entityDefs', 'Contact', 'fields'], []) as $name => $definition) {
            if (!in_array($definition['type'] ?? '', ['varchar', 'text', 'int', 'float', 'bool', 'date', 'datetime', 'enum'], true) ||
                !empty($definition['notStorable']) || !empty($definition['utility']) ||
                !$this->acl->checkField($ctx['user'], 'Contact', $name)) continue;
            $result[] = ['path' => $name, 'label' => $name, 'expression' => '{{' . $name . '}}'];
        }
        $attr = $this->fields->getAttributeName();
        if ($this->acl->checkField($ctx['user'], 'Contact', $attr)) {
            foreach ($this->fields->getGroupedMeta('Contact', $ctx['account']->get('tenantId'))['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    if (!($field['isTemplateEnabled'] ?? true)) continue;
                    $path = $attr . '.' . $field['valueKey'];
                    $result[] = ['path' => $path, 'label' => $field['label'], 'expression' => '{{' . $path . '}}'];
                }
            }
        }
        return $result;
    }

    public function capabilities(array $ctx): array
    {
        $mode = $this->access->mode($ctx['inbox']);
        $templates = [];
        if ($mode === 'Template') {
            $templates = $this->api->readTemplateCatalog((string) $ctx['platform']->get('backendUrl'),
                (string) $ctx['account']->get('apiKey'), (int) $ctx['account']->get('chatwootAccountId'),
                (int) $ctx['inbox']->get('chatwootInboxId'), new TemplateDeadline(30));
            $templates = array_values(array_filter($templates, fn ($t) => ($t['status'] ?? '') === 'APPROVED' && (new TemplateDefinition())->supported($t)));
        }
        return ['mode' => $mode, 'fields' => $this->variables($ctx), 'templates' => $templates,
            'inbox' => $ctx['inbox']->get('name'), 'pacing' => self::pacing([], $ctx['inbox']->get('channelType')), 'media_limit' => 16777216];
    }

    /** Resolve the immutable campaign delay in seconds; older campaigns use channel defaults. */
    public static function pacing(array $composition, ?string $channelType): array
    {
        if (!array_key_exists('pacing_min', $composition) && !array_key_exists('pacing_max', $composition)) {
            return array_map(fn (int $ms) => $ms / 1000, WhatsAppChannel::sendDelayWindowMs($channelType));
        }
        $options = ['options' => ['min_range' => 1, 'max_range' => 3600]];
        $min = filter_var($composition['pacing_min'] ?? null, FILTER_VALIDATE_INT, $options);
        $max = filter_var($composition['pacing_max'] ?? null, FILTER_VALIDATE_INT, $options);
        if (!WhatsAppChannel::isQrcode($channelType) || $min === false || $max === false || $min > $max) {
            throw new BadRequest('invalid_pacing');
        }
        return [$min, $max];
    }

    public function validate(array $composition, array $ctx, ?array $media): ?array
    {
        $this->assertReadable($composition, $ctx);
        $mode = $this->access->mode($ctx['inbox']);
        if (($composition['mode'] ?? '') !== $mode) throw new BadRequest('invalid_mode');
        self::pacing($composition, $ctx['inbox']->get('channelType'));
        if ($mode === 'FreeText') {
            if (!is_string($composition['body'] ?? '') || mb_strlen($composition['body'] ?? '') > 10000 ||
                (!trim($composition['body'] ?? '') && !$media)) throw new BadRequest('invalid_body');
            if ($media && (($media['bytes'] ?? 0) < 1 || $media['bytes'] > 16777216)) throw new BadRequest('invalid_media');
            return null;
        }
        if ($media) throw new BadRequest('invalid_media');
        $template = $this->preflight->check($ctx['inbox'], (string) $ctx['platform']->get('backendUrl'),
            (string) $ctx['account']->get('apiKey'), (int) $ctx['account']->get('chatwootAccountId'),
            (string) ($composition['template_name'] ?? ''), (string) ($composition['language'] ?? ''),
            $composition['body_parameters'] ?? [], $composition['header_parameters'] ?? [],
            ($composition['media_url'] ?? '') ?: null, ($composition['media_type'] ?? '') ?: null);
        if (!(new TemplateDefinition())->supported($template)) throw new BadRequest('unsupported_template');
        return $template;
    }

    public function freeze(Entity $campaign, Entity $contact, string $phone, array $ctx): string
    {
        $composition = self::array($campaign->get('managedComposition'));
        $this->assertReadable($composition, $ctx);
        $allowed = array_column($this->variables($ctx), null, 'path');
        $render = function (string $text) use ($contact, $allowed): string {
            $syntax = preg_replace('/\{\{\s*([\w.]+)\s*\}\}/u', '', $text);
            if (str_contains($syntax, '{{') || str_contains($syntax, '}}')) throw new BadRequest('unresolved_expression');
            $out = preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/u', function ($match) use ($contact, $allowed): string {
                $path = $match[1];
                if (!isset($allowed[$path])) throw new BadRequest('field_unavailable');
                $prefix = $this->fields->getAttributeName() . '.';
                $value = str_starts_with($path, $prefix)
                    ? (self::array($contact->get($this->fields->getAttributeName()))[substr($path, strlen($prefix))] ?? null)
                    : $contact->get($path);
                if (!is_scalar($value) || (!is_bool($value) && trim((string) $value) === '')) throw new BadRequest('missing_required_value');
                return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }, $text);
            return $out;
        };
        $payload = ['phone_number' => $phone, 'content' => ''];
        if ($composition['mode'] === 'FreeText') {
            $payload['content'] = $render($composition['body'] ?? '');
            $media = self::array($campaign->get('managedMedia'));
            if ($media) $payload['media_key'] = $media['key'];
            if (!trim($payload['content']) && !$media) throw new BadRequest('empty_message');
        } else {
            $template = self::array($campaign->get('managedTemplate'));
            $body = array_map($render, TemplateParameters::mapping($composition['body_parameters'] ?? []));
            $header = array_map($render, TemplateParameters::mapping($composition['header_parameters'] ?? []));
            TemplateParameters::validate($template, $body, $header, ($composition['media_url'] ?? '') ?: null, ($composition['media_type'] ?? '') ?: null);
            foreach ($template['components'] as $component) {
                if ($component['type'] === 'BODY') {
                    $payload['content'] = preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $body[(int) $m[1]], $component['text']);
                }
            }
            $payload['template_params'] = [
                'name' => $template['name'], 'language' => $template['language'], 'category' => $template['category'],
                'processed_params' => TemplateParameters::processed($body, $header, ($composition['media_url'] ?? '') ?: null, ($composition['media_type'] ?? '') ?: null),
            ];
            $payload['template_signature'] = TemplateParameters::signature($template);
        }
        if (mb_strlen($payload['content']) > 10000) throw new BadRequest('message_too_large');
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 100000) throw new BadRequest('payload_too_large');
        return $json;
    }

    /** Recheck permissions without re-rendering immutable reviewed values. */
    public function assertReadable(array $composition, array $ctx): void
    {
        if (!$this->acl->checkScope($ctx['user'], 'Contact', 'read') ||
            !$this->acl->checkField($ctx['user'], 'Contact', 'phoneNumber')) throw new Forbidden('Contact fields unavailable.');
        $allowed = array_column($this->variables($ctx), null, 'path');
        $texts = [$composition['body'] ?? ''];
        foreach (['body_parameters', 'header_parameters'] as $key) {
            foreach (TemplateParameters::mapping($composition[$key] ?? []) as $text) $texts[] = $text;
        }
        foreach ($texts as $text) {
            preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/u', (string) $text, $matches);
            foreach ($matches[1] as $path) {
                if (!isset($allowed[$path])) throw new Forbidden('Contact fields unavailable.');
            }
        }
    }

    public static function array(mixed $value): array
    {
        return json_decode(json_encode($value ?? [], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
