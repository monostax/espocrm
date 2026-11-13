<?php

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\SendWhatsAppTemplate;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyTargetPeople;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;

/**
 * An Account journey sends the template to each contact in Contatos, on that
 * contact's own phones, rendered with that contact as {{Contact.*}}.
 */
class SendWhatsAppTemplateFanOutTest extends TestCase
{
    /** @var list<array{phone: string, name: string, params: array<string, string>}> */
    private array $sent = [];

    public function testAccountSendsToEachContactWithTheirOwnName(): void
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
            ['phone' => '+5511900000001', 'name' => 'Cristina', 'params' => ['1' => 'Cristina', '2' => 'Elite']],
            ['phone' => '+5511900000002', 'name' => 'Cristina', 'params' => ['1' => 'Cristina', '2' => 'Elite']],
            ['phone' => '+5511900000003', 'name' => 'Marcos', 'params' => ['1' => 'Marcos', '2' => 'Elite']],
        ], $this->sent);
    }

    public function testOptedOutAndPhonelessContactsAreSkipped(): void
    {
        $account = $this->entity('Account', 'acc1', ['name' => 'Elite']);
        $out = $this->entity('Contact', 'c1', ['name' => 'Out', 'firstName' => 'Out', 'whatsAppOptedOut' => true]);
        $nophone = $this->entity('Contact', 'c2', ['name' => 'Nophone', 'firstName' => 'Nophone']);
        $ok = $this->entity('Contact', 'c3', ['name' => 'Ok', 'firstName' => 'Ok']);

        $people = $this->createStub(JourneyTargetPeople::class);
        $people->method('forTarget')->willReturn([$out, $nophone, $ok]);

        $outbound = $this->outbound(['c1' => ['+5511900000001'], 'c2' => [], 'c3' => ['+5511900000003']]);

        $this->action($outbound, $people)->run($this->context($account, []));

        $this->assertSame(['+5511900000003'], array_column($this->sent, 'phone'));
    }

    public function testAContactWithAnEmptyParameterIsSkippedAndTheOthersStillGetIt(): void
    {
        $account = $this->entity('Account', 'acc1', ['name' => 'Elite']);
        $blank = $this->entity('Contact', 'c1', ['name' => 'Sem nome', 'firstName' => '']);
        $marcos = $this->entity('Contact', 'c2', ['name' => 'Marcos', 'firstName' => 'Marcos']);

        $people = $this->createStub(JourneyTargetPeople::class);
        $people->method('forTarget')->willReturn([$blank, $marcos]);

        $outbound = $this->outbound(['c1' => ['+5511900000001'], 'c2' => ['+5511900000003']]);

        $this->action($outbound, $people)->run($this->context($account, []));

        $this->assertSame(['+5511900000003'], array_column($this->sent, 'phone'));
    }

    public function testWhenNobodyHasTheValueTheActionFailsNamingTheField(): void
    {
        $account = $this->entity('Account', 'acc1', ['name' => 'Elite']);
        $blank = $this->entity('Contact', 'c1', ['name' => 'Sem nome', 'firstName' => '']);

        $people = $this->createStub(JourneyTargetPeople::class);
        $people->method('forTarget')->willReturn([$blank]);

        $outbound = $this->outbound(['c1' => ['+5511900000001']]);

        try {
            $this->action($outbound, $people)->run($this->context($account, []));
            $this->fail('Expected the action to fail.');
        } catch (Error $e) {
            $this->assertSame('Sem valor: {{1}}={{Contact.firstName}} (Sem nome)', $e->getMessage());
        }

        $this->assertSame([], $this->sent);
    }

    public function testPhoneOverrideSendsOnceAgainstTheTarget(): void
    {
        $account = $this->entity('Account', 'acc1', ['name' => 'Elite']);

        $people = $this->createMock(JourneyTargetPeople::class);
        $people->expects($this->never())->method('forTarget');

        $outbound = $this->outbound([], ['+5511999999999']);
        $mapping = ['1' => '{{customFields.x}}'];

        $this->action($outbound, $people)->run(
            $this->context($account, ['phone' => '+5511999999999', 'parameterMapping' => $mapping])
        );

        $this->assertSame([
            ['phone' => '+5511999999999', 'name' => 'Elite', 'params' => ['1' => 'Elite', '2' => 'Elite']],
        ], $this->sent);
    }

    /**
     * @param array<string, list<string>> $phonesByPerson
     * @param list<string>|null $overridePhones
     */
    private function outbound(array $phonesByPerson, ?array $overridePhones = null): JourneyWhatsAppOutbound
    {
        $outbound = $this->createStub(JourneyWhatsAppOutbound::class);
        $outbound->method('resolveConnection')->willReturn(['channelType' => 'whatsappCloudApi']);
        $outbound->method('preflightTemplate')->willReturn(['category' => 'MARKETING']);
        $outbound->method('isOptedOut')->willReturnCallback(
            fn (Entity $e) => (bool) $e->get('whatsAppOptedOut')
        );
        $outbound->method('displayName')->willReturnCallback(fn (Entity $e) => (string) $e->get('name'));
        $outbound->method('resolvePhones')->willReturnCallback(
            fn (Entity $e, ?string $override = null) => $override !== null && $overridePhones !== null
                ? $overridePhones
                : ($phonesByPerson[$e->getId()] ?? [])
        );
        // Stand-in for Handlebars: {{1}} = the person's first name (or the target's
        // name when there is no person), {{2}} = the target's name.
        $outbound->method('resolveParameterMapping')->willReturnCallback(
            fn ($mapping, Entity $target, ?Entity $person = null) => empty($mapping) ? [] : [
                '1' => $person ? (string) $person->get('firstName') : (string) $target->get('name'),
                '2' => (string) $target->get('name'),
            ]
        );
        $outbound->method('sendTemplate')->willReturnCallback(
            function (array $conn, string $phone, string $name, string $tpl, string $lang, array $params) {
                $this->sent[] = ['phone' => $phone, 'name' => $name, 'params' => $params];

                return ['message_id' => 1, 'conversation_id' => 1];
            }
        );

        return $outbound;
    }

    private function action(JourneyWhatsAppOutbound $outbound, JourneyTargetPeople $people): SendWhatsAppTemplate
    {
        return new SendWhatsAppTemplate(
            $outbound,
            $this->createStub(TenantGuard::class),
            $people,
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
                'templateName' => 'golden_sun_a',
                'templateLanguage' => 'pt_BR',
                'templateBody' => 'Oi {{1}}! Post {{2}}',
                'parameterMapping' => ['1' => '{{Contact.firstName}}', '2' => '{{name}}'],
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
