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

namespace tests\unit\Espo\Modules\Chatwoot\Hooks\WhatsAppCampaign;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Hooks\WhatsAppCampaign\ValidateMessageConfiguration;
use Espo\Modules\Chatwoot\Tools\WhatsAppChannel;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

// vendor/ is installed --no-dev, so autoload-dev PSR-4 is unavailable;
// PHPUnit only includes *Test.php files, so shared helpers need an explicit require.
require_once __DIR__ . '/../../Support/EntityDouble.php';
use stdClass;

class ValidateMessageConfigurationTest extends TestCase
{
    private ValidateMessageConfiguration $hook;

    protected function setUp(): void
    {
        $this->hook = new ValidateMessageConfiguration();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function entity(array $attributes): Entity
    {
        return new EntityDouble($attributes, 'WhatsAppCampaign');
    }

    public function testQrcodeCannotUseTemplateMode(): void
    {
        $entity = $this->entity([
            'channelType' => WhatsAppChannel::QRCODE,
            'messageMode' => WhatsAppChannel::MODE_TEMPLATE,
            'templateName' => 'promo_v1',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/cannot send template messages/');

        $this->hook->beforeSave($entity, []);
    }

    public function testQrcodeFreeTextRequiresBodyOrAttachment(): void
    {
        $entity = $this->entity([
            'channelType' => WhatsAppChannel::QRCODE,
            'messageMode' => WhatsAppChannel::MODE_FREE_TEXT,
            'messageBody' => '   ',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/message body/');

        $this->hook->beforeSave($entity, []);
    }

    /**
     * A bare voice note or image with no caption is a legitimate send.
     */
    public function testAttachmentWithoutBodyIsAllowed(): void
    {
        $entity = $this->entity([
            'channelType' => WhatsAppChannel::QRCODE,
            'messageMode' => WhatsAppChannel::MODE_FREE_TEXT,
            'messageBody' => '',
            'attachmentId' => 'attach-1',
        ]);

        $this->hook->beforeSave($entity, []);

        $this->assertSame('attach-1', $entity->get('attachmentId'));
    }

    /**
     * Meta templates carry media in the template header, so a separate
     * attachment has no transport and must be rejected rather than ignored.
     */
    public function testTemplateModeRejectsAttachment(): void
    {
        $entity = $this->entity([
            'channelType' => WhatsAppChannel::CLOUD_API,
            'messageMode' => WhatsAppChannel::MODE_TEMPLATE,
            'templateName' => 'promo_v1',
            'attachmentId' => 'attach-1',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/cannot carry an attachment/');

        $this->hook->beforeSave($entity, []);
    }

    public function testQrcodeFreeTextPassesWithBody(): void
    {
        $entity = $this->entity([
            'channelType' => WhatsAppChannel::QRCODE,
            'messageMode' => WhatsAppChannel::MODE_FREE_TEXT,
            'messageBody' => 'Oi {{firstName}}!',
        ]);

        $this->hook->beforeSave($entity, []);

        $this->assertSame(WhatsAppChannel::MODE_FREE_TEXT, $entity->get('messageMode'));
        $this->assertSame('Oi {{firstName}}!', $entity->get('messageBody'));
    }

    /**
     * A campaign converted from Template to FreeText must not keep a template
     * that the send job could still pick up.
     */
    public function testFreeTextClearsTemplateAttributes(): void
    {
        $entity = $this->entity([
            'channelType' => WhatsAppChannel::CLOUD_API,
            'messageMode' => WhatsAppChannel::MODE_FREE_TEXT,
            'messageBody' => 'Hello',
            'templateName' => 'promo_v1',
            'templateLanguage' => 'pt_BR',
            'templateCategory' => 'MARKETING',
            'templateBody' => 'Hi {{1}}',
            'parameterMapping' => ['1' => '{{firstName}}'],
            'headerMediaUrl' => 'https://example.com/a.jpg',
            'headerMediaType' => 'image',
        ]);

        $this->hook->beforeSave($entity, []);

        foreach ([
            'templateName',
            'templateLanguage',
            'templateCategory',
            'templateBody',
            'parameterMapping',
            'headerMediaUrl',
            'headerMediaType',
        ] as $attribute) {
            $this->assertNull($entity->get($attribute), $attribute);
        }
    }

    public function testTemplateModeRequiresTemplateName(): void
    {
        $entity = $this->entity([
            'channelType' => WhatsAppChannel::CLOUD_API,
            'messageMode' => WhatsAppChannel::MODE_TEMPLATE,
            'templateName' => '',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/template/');

        $this->hook->beforeSave($entity, []);
    }

    public function testTemplateModeKeepsTemplateAttributes(): void
    {
        $entity = $this->entity([
            'channelType' => WhatsAppChannel::CLOUD_API,
            'messageMode' => WhatsAppChannel::MODE_TEMPLATE,
            'templateName' => 'promo_v1',
            'parameterMapping' => ['1' => '{{firstName}}'],
        ]);

        $this->hook->beforeSave($entity, []);

        $this->assertSame('promo_v1', $entity->get('templateName'));
        $this->assertSame(['1' => '{{firstName}}'], $entity->get('parameterMapping'));
    }

    /**
     * Legacy rows have no messageMode; they are template campaigns and must
     * keep validating as such.
     */
    public function testBlankModeNormalizesToTemplate(): void
    {
        $entity = $this->entity([
            'channelType' => WhatsAppChannel::CLOUD_API,
            'messageMode' => null,
            'templateName' => 'legacy_template',
        ]);

        $this->hook->beforeSave($entity, []);

        $this->assertSame(WhatsAppChannel::MODE_TEMPLATE, $entity->get('messageMode'));
    }

    /**
     * channelType is only backfilled when the inbox is touched, so an absent
     * value must not block a save.
     */
    public function testMissingChannelTypeSkipsChannelCheck(): void
    {
        $entity = $this->entity([
            'channelType' => null,
            'messageMode' => WhatsAppChannel::MODE_FREE_TEXT,
            'messageBody' => 'Hello',
        ]);

        $this->hook->beforeSave($entity, []);

        $this->assertSame(WhatsAppChannel::MODE_FREE_TEXT, $entity->get('messageMode'));
    }

    public function testSilentSaveSkipsValidation(): void
    {
        $entity = $this->entity([
            'channelType' => WhatsAppChannel::QRCODE,
            'messageMode' => WhatsAppChannel::MODE_TEMPLATE,
            'templateName' => 'promo_v1',
        ]);

        $this->hook->beforeSave($entity, ['silent' => true]);

        // Untouched: silent saves (counter updates, status transitions) must
        // never be rejected by content validation.
        $this->assertSame(WhatsAppChannel::MODE_TEMPLATE, $entity->get('messageMode'));
    }
}
