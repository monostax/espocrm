<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureSelfService\Tools\Signup\Signups;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Acting;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Assistant;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Avisos;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Cards;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Charges;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Deck;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Inbound;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\PhoneCodes;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Sessions;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Subscriptions;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Taps;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Tokens;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Workspaces;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TapsTest extends TestCase
{
    private Acting&MockObject $acting;
    private Cards&MockObject $cards;
    private Avisos&MockObject $avisos;
    private Deck&MockObject $deck;
    /** @var list<string> what the person was told (Cards::then and Avisos::text) */
    private array $told = [];

    /** An entity backed by an array, enough for get/set/getId. BaseEntity, whose set() takes one array like the code does. */
    private function entity(string $id, array $values): Entity
    {
        $data = ['id' => $id, ...$values];
        $entity = $this->createMock(BaseEntity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(function (string $name) use (&$data) {
            return $data[$name] ?? null;
        });
        $entity->method('set')->willReturnCallback(function ($name, $value = null) use (&$data, $entity) {
            foreach (is_array($name) ? $name : [$name => $value] as $key => $item) $data[$key] = $item;
            return $entity;
        });
        return $entity;
    }

    private function tokens(): Tokens
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn (string $name) => $name === 'cryptKey' ? 'test-crypt-key' : null);
        return new Tokens(new Settings($config));
    }

    private function taps(): Taps
    {
        $this->acting = $this->createMock(Acting::class);
        $this->cards = $this->createMock(Cards::class);
        $this->avisos = $this->createMock(Avisos::class);
        $this->deck = $this->createMock(Deck::class);
        $workspaces = $this->createMock(Workspaces::class);
        $workspaces->method('of')->willReturn(['language' => 'pt_BR', 'frozen' => false, 'name' => 'Clínica']);
        $workspaces->method('now')->willReturn(new DateTimeImmutable('2026-11-12 15:20', new DateTimeZone('America/Sao_Paulo')));
        $this->cards->method('then')->willReturnCallback(function ($subscription, string $text) {
            $this->told[] = $text;
        });
        $this->avisos->method('text')->willReturnCallback(function ($subscription, string $kind, string $text) {
            $this->told[] = $text;
            return null;
        });
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn(null);
        return new Taps($em, $this->deck, $this->cards, $this->acting, $this->avisos, $workspaces,
            $this->createMock(Subscriptions::class), $this->tokens(), $this->createMock(Log::class));
    }

    private function card(array $values = []): Entity
    {
        return $this->entity('card1', ['subscriptionId' => 'sub1', 'lane' => 'service', 'subjectId' => 'conv1', 'status' => 'shown',
            'verdict' => 'customer_waiting', 'contactName' => 'Gelsa', 'draft' => 'Oi Gelsa! Já te mando os horários.', ...$values]);
    }

    private function subscription(string $id = 'sub1', array $values = []): Entity
    {
        return $this->entity($id, ['status' => 'active', 'phone' => '+5591999990000', 'morningEnabled' => true, 'morningTime' => '08:00', ...$values]);
    }

    public function testEnviarSendsTheStoredDraftAsThePerson(): void
    {
        $taps = $this->taps();
        $card = $this->card();
        $subscription = $this->subscription();
        $this->acting->expects($this->once())->method('send')
            ->with($subscription, $card, 'Oi Gelsa! Já te mando os horários.')->willReturn(Acting::SENT);
        $taps->cardTap($subscription, $card, ['kind' => 'c', 'id' => 'card1', 'action' => 's']);
        $this->assertSame('sent', $card->get('status'));
        $this->assertSame(['service:conv1'], Sessions::skipped($subscription));
        $this->assertStringContainsString('Enviado para *Gelsa*', $this->told[0]);
    }

    public function testAConversationThatMovedIsNeverAnsweredFromTheOldCard(): void
    {
        $taps = $this->taps();
        $card = $this->card();
        $this->acting->method('send')->willReturn(Acting::CHANGED);
        $taps->cardTap($this->subscription(), $card, ['kind' => 'c', 'id' => 'card1', 'action' => 's']);
        $this->assertSame('stale', $card->get('status'));
        $this->assertStringContainsString('Essa conversa mudou', $this->told[0]);
    }

    public function testAHandledCardsButtonsNoLongerAct(): void
    {
        $taps = $this->taps();
        $this->acting->expects($this->never())->method('send');
        $this->acting->expects($this->never())->method('resolve');
        foreach (['s', 'r', 'v', 'h'] as $action) {
            $taps->cardTap($this->subscription(), $this->card(['status' => 'sent']), ['kind' => 'c', 'id' => 'card1', 'action' => $action]);
        }
        $this->assertSame(array_fill(0, 4, 'Esse card já foi tratado.'), $this->told);
    }

    public function testEditarTakesTheNextTextAndStillAsksBeforeSending(): void
    {
        $taps = $this->taps();
        $card = $this->card();
        $subscription = $this->subscription();
        $taps->cardTap($subscription, $card, ['kind' => 'c', 'id' => 'card1', 'action' => 'e']);
        $this->assertSame('card1', Sessions::editing($subscription));
        $this->assertStringContainsString('Escreva aqui a resposta para *Gelsa*', $this->told[0]);

        $buttons = null;
        $this->avisos->method('buttons')->willReturnCallback(function ($s, $kind, $text, array $items) use (&$buttons) {
            $buttons = $items;
            return null;
        });
        $this->acting->expects($this->never())->method('send');
        $taps->edited($subscription, $card, 'Oi Gelsa, a Sabrina te chama hoje.');
        $this->assertSame('Oi Gelsa, a Sabrina te chama hoje.', $card->get('editText'));
        $actions = array_map(fn (array $item) => $this->tokens()->parse($item[1])['action'], $buttons);
        $this->assertSame(['c', 'x'], $actions);
    }

    public function testConfirmingAnEditSendsThePersonsText(): void
    {
        $taps = $this->taps();
        $card = $this->card(['editText' => 'Minha versão']);
        $this->acting->expects($this->once())->method('send')->with($this->anything(), $card, 'Minha versão')->willReturn(Acting::SENT);
        $taps->cardTap($this->subscription(), $card, ['kind' => 'c', 'id' => 'card1', 'action' => 'c']);
    }

    public function testMeLembreSnoozesAndSchedulesTheReminderInUtc(): void
    {
        $taps = $this->taps();
        $card = $this->card();
        $this->acting->expects($this->once())->method('snooze')->willReturnCallback(function ($s, $c, DateTimeImmutable $until) {
            $this->assertSame('2026-11-13 08:00', $until->format('Y-m-d H:i'));
            return true;
        });
        $taps->cardTap($this->subscription(), $card, ['kind' => 'c', 'id' => 'card1', 'action' => 't']);
        $this->assertSame('snoozed', $card->get('status'));
        $this->assertSame('2026-11-13 11:00:00', $card->get('remindAt'));
        $this->assertStringContainsString('amanhã às 8h', $this->told[0]);
    }

    public function testATapFromAnotherNumberIsIgnored(): void
    {
        $tokens = $this->tokens();
        $card = $this->card();
        $subscription = $this->subscription();
        $em = $this->createMock(EntityManager::class);
        $em->method('createEntity')->willReturn($this->entity('log', []));
        $em->method('getEntityById')->willReturnCallback(fn (string $type, string $id) => match ($type) {
            'WhatsAppDeckCard' => $card, 'WhatsAppNotificationSubscription' => $subscription, default => null,
        });
        $avisos = $this->createMock(Avisos::class);
        $avisos->method('isAvisos')->willReturn(true);
        $subscriptions = $this->createMock(Subscriptions::class);
        $subscriptions->method('activeForPhone')->willReturn([]);
        $taps = $this->createMock(Taps::class);
        $taps->expects($this->never())->method('cardTap');
        $taps->expects($this->never())->method('next');
        $inbound = new Inbound($em, $avisos, $tokens, $this->createMock(PhoneCodes::class), $subscriptions, $taps,
            $this->createMock(Workspaces::class), $this->createMock(Signups::class), $this->createMock(Charges::class), $this->createMock(Assistant::class), $this->createMock(Log::class));
        $handled = $inbound->handle((object) [
            'event' => 'message_created', 'message_type' => 'incoming', 'id' => 501, 'inbox' => (object) ['id' => 74],
            'conversation' => (object) ['id' => 9], 'sender' => (object) ['id' => 3, 'phone_number' => '+5511988887777'],
            'content' => 'Ver sugestão de resposta', 'content_attributes' => (object) ['reply_payload' => $tokens->card('card1', 's')],
        ], 'acct6');
        $this->assertTrue($handled);
    }

    public function testATapFromTheVerifiedNumberActs(): void
    {
        $tokens = $this->tokens();
        $card = $this->card();
        $subscription = $this->subscription();
        $em = $this->createMock(EntityManager::class);
        $em->method('createEntity')->willReturn($this->entity('log', []));
        $em->method('getEntityById')->willReturnCallback(fn (string $type, string $id) => match ($type) {
            'WhatsAppDeckCard' => $card, 'WhatsAppNotificationSubscription' => $subscription, default => null,
        });
        $avisos = $this->createMock(Avisos::class);
        $avisos->method('isAvisos')->willReturn(true);
        $subscriptions = $this->createMock(Subscriptions::class);
        $subscriptions->method('activeForPhone')->willReturn([$subscription]);
        $taps = $this->createMock(Taps::class);
        $taps->expects($this->once())->method('cardTap')->with($subscription, $card, ['kind' => 'c', 'id' => 'card1', 'action' => 's']);
        $inbound = new Inbound($em, $avisos, $tokens, $this->createMock(PhoneCodes::class), $subscriptions, $taps,
            $this->createMock(Workspaces::class), $this->createMock(Signups::class), $this->createMock(Charges::class), $this->createMock(Assistant::class), $this->createMock(Log::class));
        $inbound->handle((object) [
            'event' => 'message_created', 'message_type' => 'incoming', 'id' => 502, 'inbox' => (object) ['id' => 74],
            'conversation' => (object) ['id' => 9], 'sender' => (object) ['id' => 3, 'phone_number' => '+5591999990000'],
            'content' => 'Ver sugestão de resposta', 'content_attributes' => (object) ['reply_payload' => $tokens->card('card1', 's')],
        ], 'acct6');
        // The tap opened Meta's 24h window for that number.
        $this->assertNotNull($subscription->get('lastInboundAt'));
    }

    public function testParadaWordsStopEveryWorkspaceOfTheNumber(): void
    {
        foreach (['PARAR', '🔕 Parar avisos', 'stop!'] as $index => $text) {
            $em = $this->createMock(EntityManager::class);
            $em->method('createEntity')->willReturn($this->entity('log', []));
            $avisos = $this->createMock(Avisos::class);
            $avisos->method('isAvisos')->willReturn(true);
            $subscriptions = $this->createMock(Subscriptions::class);
            $subscriptions->method('activeForPhone')->willReturn([$this->subscription()]);
            $subscriptions->expects($this->once())->method('stopPhone')->with('+5591999990000', 'keyword');
            $taps = $this->createMock(Taps::class);
            $taps->expects($this->never())->method('next');
            $inbound = new Inbound($em, $avisos, $this->tokens(), $this->createMock(PhoneCodes::class), $subscriptions, $taps,
                $this->createMock(Workspaces::class), $this->createMock(Signups::class), $this->createMock(Charges::class), $this->createMock(Assistant::class), $this->createMock(Log::class));
            $inbound->handle((object) [
                'event' => 'message_created', 'message_type' => 'incoming', 'id' => 600 + $index, 'inbox' => (object) ['id' => 74],
                'conversation' => (object) ['id' => 9], 'sender' => (object) ['id' => 3, 'phone_number' => '+5591999990000'], 'content' => $text,
            ], 'acct6');
        }
    }

    public function testTheDeckWordSendsTheNextCard(): void
    {
        foreach (['em dia', 'Próximo', 'EM DIA!'] as $index => $text) {
            $subscription = $this->subscription();
            $taps = $this->createMock(Taps::class);
            $taps->expects($this->once())->method('next')->with($subscription);
            $assistant = $this->createMock(Assistant::class);
            $assistant->expects($this->never())->method('ask');
            $this->inbound([$subscription], $taps, $assistant)->handle($this->incoming(700 + $index, $text), 'acct6');
        }
    }

    public function testAnyOtherTextFromASubscriberGoesToTheirAi(): void
    {
        $subscription = $this->subscription();
        $taps = $this->createMock(Taps::class);
        $taps->expects($this->never())->method('next');
        $assistant = $this->createMock(Assistant::class);
        $assistant->expects($this->once())->method('ask')->with($subscription, 'Quem está esperando resposta?', 710);
        $this->inbound([$subscription], $taps, $assistant)->handle($this->incoming(710, 'Quem está esperando resposta?'), 'acct6');
    }

    public function testSeveralWorkspacesAskWhichOneAndTheTextWaits(): void
    {
        $first = $this->subscription('sub1');
        $second = $this->subscription('sub2');
        $assistant = $this->createMock(Assistant::class);
        $assistant->expects($this->never())->method('ask');
        $avisos = $this->createMock(Avisos::class);
        $avisos->method('isAvisos')->willReturn(true);
        $avisos->expects($this->once())->method('buttons')->with($first, 'reply', 'Em qual espaço eu procuro?');
        $workspaces = $this->createMock(Workspaces::class);
        $workspaces->method('of')->willReturn(['language' => 'pt_BR', 'frozen' => false, 'name' => 'Clínica']);
        $this->inbound([$first, $second], $this->createMock(Taps::class), $assistant, $avisos, $workspaces)
            ->handle($this->incoming(720, 'Como foi a reunião de ontem?'), 'acct6');
        $this->assertSame(['text' => 'Como foi a reunião de ontem?', 'messageId' => 720], Sessions::pending($first));
        $this->assertSame(['text' => 'Como foi a reunião de ontem?', 'messageId' => 720], Sessions::pending($second));
    }

    public function testTheWorkspaceLastTalkedToGetsTheText(): void
    {
        $first = $this->subscription('sub1', ['session' => (object) ['aiAt' => time() - 600]]);
        $second = $this->subscription('sub2', ['session' => (object) ['aiAt' => time() - 60]]);
        $assistant = $this->createMock(Assistant::class);
        $assistant->expects($this->once())->method('ask')->with($second, 'E o funil?', 730);
        $this->inbound([$first, $second], $this->createMock(Taps::class), $assistant)->handle($this->incoming(730, 'E o funil?'), 'acct6');
    }

    public function testChoosingAWorkspaceSendsTheWaitingTextThere(): void
    {
        $tokens = $this->tokens();
        $subscription = $this->subscription('sub2');
        Sessions::setPending($subscription, 'Como foi a reunião de ontem?', 720);
        $taps = $this->createMock(Taps::class);
        $taps->expects($this->never())->method('subscriptionTap');
        $assistant = $this->createMock(Assistant::class);
        $assistant->expects($this->once())->method('ask')->with($subscription, 'Como foi a reunião de ontem?', 720);
        $this->inbound([$subscription], $taps, $assistant, null, null, $tokens)->handle($this->incoming(740, 'Clínica', [
            'content_attributes' => (object) ['reply_payload' => $tokens->subscription('sub2', 'w')],
        ]), 'acct6');
        $this->assertNull(Sessions::pending($subscription));
    }

    public function testDeckAndSwitchWordsAreExactCommandsOnly(): void
    {
        $this->assertSame('deck', Inbound::command('Em dia'));
        $this->assertSame('deck', Inbound::command('próximo 👉'));
        $this->assertSame('switch', Inbound::command('Trocar espaço'));
        $this->assertNull(Inbound::command('o que tem em dia hoje?'));
        $this->assertNull(Inbound::command('próximo passo com a Gelsa?'));
    }

    /** @param list<Entity> $subscriptions */
    private function inbound(array $subscriptions, Taps $taps, Assistant $assistant, ?Avisos $avisos = null, ?Workspaces $workspaces = null,
        ?Tokens $tokens = null): Inbound
    {
        $byId = [];
        foreach ($subscriptions as $subscription) $byId[$subscription->getId()] = $subscription;
        $em = $this->createMock(EntityManager::class);
        $em->method('createEntity')->willReturn($this->entity('log', []));
        $em->method('getEntityById')->willReturnCallback(fn (string $type, string $id) => $type === 'WhatsAppNotificationSubscription' ? ($byId[$id] ?? null) : null);
        if (!$avisos) {
            $avisos = $this->createMock(Avisos::class);
            $avisos->method('isAvisos')->willReturn(true);
        }
        $list = $this->createMock(Subscriptions::class);
        $list->method('activeForPhone')->willReturn($subscriptions);
        return new Inbound($em, $avisos, $tokens ?? $this->tokens(), $this->createMock(PhoneCodes::class), $list, $taps,
            $workspaces ?? $this->createMock(Workspaces::class), $this->createMock(Signups::class), $this->createMock(Charges::class), $assistant,
            $this->createMock(Log::class));
    }

    private function incoming(int $id, string $text, array $extra = []): object
    {
        return (object) ['event' => 'message_created', 'message_type' => 'incoming', 'id' => $id, 'inbox' => (object) ['id' => 74],
            'conversation' => (object) ['id' => 9], 'sender' => (object) ['id' => 3, 'phone_number' => '+5591999990000'], 'content' => $text, ...$extra];
    }
}
