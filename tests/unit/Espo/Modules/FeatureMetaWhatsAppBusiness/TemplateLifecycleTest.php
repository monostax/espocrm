<?php

namespace tests\unit\Espo\Modules\FeatureMetaWhatsAppBusiness;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\MetaGraphApiClient;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\TemplateAccess;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppBusinessAccountMessageTemplate;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDefinition;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateSample;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\Tokens;
use Espo\Tools\OAuth\TokensProvider;
use PHPUnit\Framework\TestCase;

class TemplateLifecycleTest extends TestCase
{
    private function service(MetaGraphApiClient $api): WhatsAppBusinessAccountMessageTemplate
    {
        $account = $this->createStub(Entity::class);
        $account->method('get')->willReturnCallback(fn ($key) => $key === 'providerId' ? 'provider' : 'Account');
        $access = $this->createStub(TemplateAccess::class);
        $access->method('resolve')->willReturn(['oAuthAccountId' => 'oauth', 'businessAccountId' => '123', 'account' => $account]);
        $tokens = $this->createStub(TokensProvider::class);
        $tokens->method('get')->willReturn(new Tokens(accessToken: 'secret', refreshToken: null, expiresAt: null));
        $em = $this->createStub(EntityManager::class);
        $em->method('getNewEntity')->willReturnCallback(function ($type) {
            $fields = json_decode(file_get_contents('custom/Espo/Modules/FeatureMetaWhatsAppBusiness/Resources/metadata/entityDefs/' . $type . '.json'), true)['fields'];
            return new \Espo\ORM\BaseEntity($type, ['attributes' => ['id' => ['type' => 'varchar']] + $fields]);
        });
        return new WhatsAppBusinessAccountMessageTemplate($em, $api,
            $tokens, $access, new TemplateDefinition(), new TemplateSample());
    }

    private function input(): array
    {
        return ['templateId' => '1', 'name' => 'order_ready', 'language' => 'pt_BR', 'definition' => TemplateDefinitionTest::definition()];
    }

    public function testPagesPreserveVirtualIdsRawComponentsAndUnknownStatuses(): void
    {
        $api = $this->createStub(MetaGraphApiClient::class);
        $template = TemplateDefinitionTest::definition() + ['id' => '1', 'status' => 'FUTURE_STATUS'];
        $template['components'][] = ['type' => 'CAROUSEL', 'cards' => [['future' => true]]];
        $api->method('getMessageTemplatePage')->willReturn(['list' => [$template], 'nextCursor' => 'next', 'hasMore' => true]);
        $page = $this->service($api)->listPage([], new TemplateDeadline());
        $this->assertSame('oauth_1', $page->list[0]->id);
        $this->assertSame('FUTURE_STATUS', $page->list[0]->status);
        $this->assertSame($template['components'], json_decode($page->list[0]->components, true));
        $this->assertFalse($page->list[0]->canEdit);
        $this->assertFalse($page->list[0]->canDelete);
        $this->assertFalse(property_exists($page, 'total'));
        $this->assertSame('next', $page->nextCursor);
    }

    public function testLegacyReadFailureNeverBecomesEmptySuccess(): void
    {
        $api = $this->createStub(MetaGraphApiClient::class);
        $api->method('getMessageTemplates')->willThrowException(new \Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateFailure(
            'dependency', 'Unavailable'
        ));
        $this->expectException(\Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateFailure::class);
        $this->service($api)->find('oauth', '123');
    }

    public function testCrossWabaIdCannotUploadOrMutate(): void
    {
        $api = $this->createMock(MetaGraphApiClient::class);
        $api->method('getMessageTemplates')->willReturn([]);
        $api->expects($this->never())->method('uploadTemplateSample');
        $api->expects($this->never())->method('updateTemplate');
        $this->expectException(NotFound::class);
        $this->service($api)->mutate('update', $this->input(), '/untrusted-path', new TemplateDeadline());
    }

    /** @dataProvider prohibitedEdits */
    public function testEligibilityIsRevalidatedBeforeMutation(array $current, array $input): void
    {
        $api = $this->createMock(MetaGraphApiClient::class);
        $api->method('getMessageTemplates')->willReturn([$current]);
        $api->expects($this->never())->method('updateTemplate');
        $api->expects($this->never())->method('uploadTemplateSample');
        $this->expectException(BadRequest::class);
        $this->service($api)->mutate('update', $input, null, new TemplateDeadline());
    }

    public static function prohibitedEdits(): array
    {
        $definition = TemplateDefinitionTest::definition();
        $current = $definition + ['id' => '1', 'status' => 'APPROVED'];
        $input = ['templateId' => '1', 'name' => 'order_ready', 'language' => 'pt_BR', 'definition' => $definition];
        $category = $input; $category['definition']['category'] = 'MARKETING';
        $name = $input; $name['definition']['name'] = 'changed';
        $language = $input; $language['language'] = 'en_US';
        $advanced = $current; $advanced['components'][] = ['type' => 'CAROUSEL'];
        return [[$current, $category], [$current, $name], [$current, $language],
            [array_replace($current, ['status' => 'PENDING']), $input], [$advanced, $input]];
    }

    public function testApprovedEditIsLastSaveWinsAndAcknowledgementDoesNotDependOnRefresh(): void
    {
        $api = $this->createMock(MetaGraphApiClient::class);
        $current = TemplateDefinitionTest::definition() + ['id' => '1', 'status' => 'APPROVED'];
        $current['components'][1]['text'] = 'Externally edited {{1}}';
        $api->expects($this->once())->method('getMessageTemplates')->willReturn([$current]);
        $api->expects($this->once())->method('updateTemplate')->with('secret', '1',
            ['components' => TemplateDefinitionTest::definition()['components']], $this->isInstanceOf(TemplateDeadline::class))
            ->willReturn(['success' => true]);
        $result = $this->service($api)->mutate('update', $this->input(), null, new TemplateDeadline());
        $this->assertTrue($result->accepted);
    }

    public function testMediaEditAlwaysRequiresFreshSample(): void
    {
        $input = $this->input();
        $input['definition']['components'][0] = ['type' => 'HEADER', 'format' => 'IMAGE',
            'example' => ['header_handle' => ['old-handle']]];
        $current = $input['definition'] + ['id' => '1', 'status' => 'APPROVED'];
        $api = $this->createMock(MetaGraphApiClient::class);
        $api->method('getMessageTemplates')->willReturn([$current]);
        $api->expects($this->never())->method('updateTemplate');
        $this->expectException(BadRequest::class);
        $this->service($api)->mutate('update', $input, null, new TemplateDeadline());
    }

    public function testDeleteExactVariantAndDisabledBlock(): void
    {
        $current = TemplateDefinitionTest::definition() + ['id' => '1', 'status' => 'APPROVED'];
        $api = $this->createMock(MetaGraphApiClient::class);
        $api->method('getMessageTemplates')->willReturn([$current, array_replace($current, ['id' => '2', 'language' => 'en_US'])]);
        $api->expects($this->once())->method('deleteTemplate')->with('secret', '123', '1', 'order_ready',
            $this->isInstanceOf(TemplateDeadline::class))->willReturn(['success' => true]);
        $this->assertTrue($this->service($api)->mutate('delete', $this->input(), null, new TemplateDeadline())->accepted);

        $blocked = $this->createMock(MetaGraphApiClient::class);
        $blocked->method('getMessageTemplates')->willReturn([array_replace($current, ['status' => 'DISABLED'])]);
        $blocked->expects($this->never())->method('deleteTemplate');
        $this->expectException(BadRequest::class);
        $this->service($blocked)->mutate('delete', $this->input(), null, new TemplateDeadline());
    }
}
