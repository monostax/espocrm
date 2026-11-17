<?php

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Htmlizer\TemplateRendererFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\SendSms;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyTargetPeople;
use Espo\Modules\FeatureJourney\Services\JourneyUndeliveredNotice;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\TemplateSendPreflight;
use Espo\Modules\Global\Tools\CustomField\TemplateBridge;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * An Account journey sends the SMS to each contact in Contatos, on that contact's
 * mobiles, with the text's placeholders filled for that contact.
 */
class SendSmsTest extends TestCase
{
    /** @var list<array{phone: string, name: string, text: string}> */
    private array $sent = [];

    /** @var array<string, string> person name => reason posted on the record's mural */
    private array $posted = [];

    /** @var list<string> channel lists the action asked the inbox to be one of */
    private array $channels = [];

    public function testAccountSendsToEachContactWithTheirOwnText(): void
    {
        $account = $this->entity('Account', 'acc1', ['name' => 'Elite']);
        $cristina = $this->entity('Contact', 'c1', ['name' => 'Cristina', 'firstName' => 'Cristina']);
        $marcos = $this->entity('Contact', 'c2', ['name' => 'Marcos', 'firstName' => 'Marcos']);

        $people = $this->createMock(JourneyTargetPeople::class);
        $people->expects($this->once())->method('forTarget')->with($account, 't1')
            ->willReturn([$cristina, $marcos]);

        $outbound = $this->outbound([
            'c1' => ['+5511900000001', '+5511900000002'],
            'c2' => ['+5511900000003'],
        ]);

        $this->action($outbound, $people)->run($this->context($account, []));

        $this->assertSame([
            ['phone' => '+5511900000001', 'name' => 'Cristina', 'text' => 'Oi Cristina! Aqui é da Elite.'],
            ['phone' => '+5511900000002', 'name' => 'Cristina', 'text' => 'Oi Cristina! Aqui é da Elite.'],
            ['phone' => '+5511900000003', 'name' => 'Marcos', 'text' => 'Oi Marcos! Aqui é da Elite.'],
        ], $this->sent);
        $this->assertSame(['sms'], $this->channels);
    }

    public function testAContactWithAnEmptyPlaceholderIsSkippedAndTheOthersStillGetIt(): void
    {
        $account = $this->entity('Account', 'acc1', ['name' => 'Elite']);
        $blank = $this->entity('Contact', 'c1', ['name' => 'Sem nome', 'firstName' => '']);
        $nophone = $this->entity('Contact', 'c2', ['name' => 'Nophone', 'firstName' => 'Nophone']);
        $marcos = $this->entity('Contact', 'c3', ['name' => 'Marcos', 'firstName' => 'Marcos']);

        $people = $this->createStub(JourneyTargetPeople::class);
        $people->method('forTarget')->willReturn([$blank, $nophone, $marcos]);

        $outbound = $this->outbound(['c1' => ['+5511900000001'], 'c2' => [], 'c3' => ['+5511900000003']]);

        $this->action($outbound, $people)->run($this->context($account, []));

        $this->assertSame(['+5511900000003'], array_column($this->sent, 'phone'));
        $this->assertSame(
            ['Sem nome' => 'sem valor em `{{contact.firstName}}`', 'Nophone' => 'sem celular'],
            $this->posted,
        );
    }

    public function testWhenNobodyHasTheValueTheActionFailsNamingTheField(): void
    {
        $account = $this->entity('Account', 'acc1', ['name' => 'Elite']);
        $blank = $this->entity('Contact', 'c1', ['name' => 'Sem nome', 'firstName' => '']);

        $people = $this->createStub(JourneyTargetPeople::class);
        $people->method('forTarget')->willReturn([$blank]);

        try {
            $this->action($this->outbound(['c1' => ['+5511900000001']]), $people)
                ->run($this->context($account, []));
            $this->fail('Expected the action to fail.');
        } catch (Error $e) {
            $this->assertSame('Sem valor: {{contact.firstName}} (Sem nome)', $e->getMessage());
        }

        $this->assertSame([], $this->sent);
    }

    public function testPhoneOverrideSendsOnceAgainstTheTarget(): void
    {
        $account = $this->entity('Account', 'acc1', ['name' => 'Elite']);

        $people = $this->createMock(JourneyTargetPeople::class);
        $people->expects($this->never())->method('forTarget');

        $this->action($this->outbound([], ['+5511999999999']), $people)->run(
            $this->context($account, ['phone' => '+5511999999999', 'body' => 'Aviso da {{name}}'])
        );

        $this->assertSame(
            [['phone' => '+5511999999999', 'name' => 'Elite', 'text' => 'Aviso da Elite']],
            $this->sent,
        );
    }

    public function testRenderTextFillsEachPlaceholderAndReportsTheEmptyOnes(): void
    {
        $outbound = $this->getMockBuilder(JourneyWhatsAppOutbound::class)
            ->setConstructorArgs([
                $this->createStub(EntityManager::class),
                $this->createStub(ChatwootApiClient::class),
                $this->createStub(TenantGuard::class),
                $this->createStub(TemplateRendererFactory::class),
                $this->createStub(TemplateBridge::class),
                $this->createStub(Log::class),
                $this->createStub(TemplateSendPreflight::class),
            ])
            ->onlyMethods(['resolveParameterMapping'])
            ->getMock();
        $outbound->method('resolveParameterMapping')->willReturnCallback(
            function (array $expressions): array {
                $values = ['{{contact.firstName}}' => 'Ana', '{{contact.emailAddress}}' => ''];
                $out = [];
                foreach ($expressions as $i => $expression) {
                    $out[(string) $i] = $values[$expression] ?? '';
                }

                return $out;
            }
        );
        $target = $this->entity('Account', 'acc1', []);

        $this->assertSame(
            ['text' => 'Oi Ana! Ana, te mandei um e-mail no .', 'empty' => ['{{contact.emailAddress}}']],
            $outbound->renderText(
                'Oi {{contact.firstName}}! {{contact.firstName}}, te mandei um e-mail no {{contact.emailAddress}}.',
                $target,
            ),
        );
        $this->assertSame(
            ['text' => 'Sem variáveis', 'empty' => []],
            $outbound->renderText('Sem variáveis', $target),
        );
    }

    /**
     * @param array<string, list<string>> $phonesByPerson
     * @param list<string>|null $overridePhones
     */
    private function outbound(array $phonesByPerson, ?array $overridePhones = null): JourneyWhatsAppOutbound
    {
        $outbound = $this->createStub(JourneyWhatsAppOutbound::class);
        $outbound->method('resolveConnection')->willReturnCallback(
            function (string $inboxId, string $tenantId, array $allowed) {
                $this->channels = $allowed;

                return ['channelType' => 'sms'];
            }
        );
        $outbound->method('displayName')->willReturnCallback(fn (Entity $e) => (string) $e->get('name'));
        $outbound->method('resolvePhones')->willReturnCallback(
            function (Entity $e, ?string $override = null, bool $whatsAppIdentities = true) use (
                $phonesByPerson,
                $overridePhones,
            ) {
                $this->assertFalse($whatsAppIdentities, 'SMS must not use WhatsApp identities.');

                return $override !== null && $overridePhones !== null
                    ? $overridePhones
                    : ($phonesByPerson[$e->getId()] ?? []);
            }
        );
        // Stand-in for Handlebars: {{contact.firstName}} = the person's first name,
        // {{name}} = the target's name.
        $outbound->method('renderText')->willReturnCallback(
            function (string $text, Entity $target, ?Entity $person = null): array {
                $first = $person ? (string) $person->get('firstName') : '';
                $empty = str_contains($text, '{{contact.firstName}}') && $first === ''
                    ? ['{{contact.firstName}}']
                    : [];

                return [
                    'text' => strtr($text, [
                        '{{contact.firstName}}' => $first,
                        '{{name}}' => (string) $target->get('name'),
                    ]),
                    'empty' => $empty,
                ];
            }
        );
        $outbound->method('sendFreeText')->willReturnCallback(
            function (array $conn, string $phone, string $name, string $text) {
                $this->sent[] = ['phone' => $phone, 'name' => $name, 'text' => $text];

                return ['message_id' => 1, 'conversation_id' => 1];
            }
        );

        return $outbound;
    }

    private function action(JourneyWhatsAppOutbound $outbound, JourneyTargetPeople $people): SendSms
    {
        $notice = $this->createStub(JourneyUndeliveredNotice::class);
        $notice->method('post')->willReturnCallback(
            function ($context, string $what, array $missed) {
                $this->posted = $missed;
            }
        );

        return new SendSms(
            $outbound,
            $this->createStub(TenantGuard::class),
            $people,
            $notice,
            $this->createStub(Log::class),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function context(Entity $target, array $params): ActionContext
    {
        return new ActionContext(
            $target,
            $this->entity('JourneyRecord', 'r1', []),
            $this->entity('JourneyStage', 's1', []),
            $this->entity('Journey', 'j1', []),
            'OnEnter',
            $params + [
                'chatwootInboxId' => 'inbox1',
                'body' => 'Oi {{contact.firstName}}! Aqui é da {{name}}.',
            ],
            't1',
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function entity(string $type, string $id, array $values): Entity
    {
        $entity = $this->createStub(Entity::class);
        $entity->method('getEntityType')->willReturn($type);
        $entity->method('getId')->willReturn($id);
        $entity->method('hasId')->willReturn(true);
        $entity->method('get')->willReturnCallback(fn (string $attr) => $values[$attr] ?? null);

        return $entity;
    }
}
