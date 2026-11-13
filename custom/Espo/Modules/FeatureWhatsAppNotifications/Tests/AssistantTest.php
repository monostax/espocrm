<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tests;

use Espo\Core\Utils\Log;
use Espo\Entities\Note;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Assistant;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Avisos;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\WhatsAppText;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Workspaces;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class AssistantTest extends TestCase
{
    /** @var list<array{string, ?string}> text and send key of each message sent */
    private array $sent = [];

    public function testMarkdownReadsAsWhatsAppFormatting(): void
    {
        $markdown = "## Resumo da semana\n\n**3 clientes** esperando resposta, *dois* há mais de um dia.\n\n"
            . "* [Gelsa Lima](#crm-reference/v1/record/Contact/6acccc3b6aa525d47)\n* [Victalab](https://victalab.com.br) ~~perdido~~\n\n"
            . "| Cliente | Valor |\n| --- | ---: |\n| IBM | R\$ 20.000 |\n\n---\n\nUse `em dia` para ver.\n\n```\nlinha 1\n```";
        $this->assertSame("*Resumo da semana*\n\n*3 clientes* esperando resposta, _dois_ há mais de um dia.\n\n"
            . "- Gelsa Lima\n- Victalab (https://victalab.com.br) ~perdido~\n\nCliente · Valor\nIBM · R\$ 20.000\n\n"
            . "Use `em dia` para ver.\n\n```\nlinha 1\n```", WhatsAppText::fromMarkdown($markdown));
        $this->assertSame('https://monostax.ai', WhatsAppText::fromMarkdown('[https://monostax.ai](https://monostax.ai)'));
        $this->assertSame('R$ 1.000 * 2', WhatsAppText::fromMarkdown('R$ 1.000 \* 2'));
        $this->assertSame('snake_case_name stays', WhatsAppText::fromMarkdown('snake_case_name stays'));
    }

    public function testLongAnswersSplitAtParagraphsWithinMetasLimit(): void
    {
        $paragraph = str_repeat('palavra ', 300);
        $chunks = WhatsAppText::chunks(trim($paragraph) . "\n\n" . trim($paragraph) . "\n\n" . str_repeat('x', 9000));
        foreach ($chunks as $chunk) $this->assertLessThanOrEqual(WhatsAppText::CHUNK, mb_strlen($chunk));
        $this->assertSame(trim($paragraph), $chunks[0]);
        $this->assertSame(['curto'], WhatsAppText::chunks('curto'));
    }

    public function testACompletedWhatsAppTurnGoesBackToTheNumberThatAsked(): void
    {
        $this->assistant($this->world())->reply($this->reply('completed', '**Oi!** Tudo certo.'));
        $this->assertSame([["*Oi!* Tudo certo.\n\n*✦ IA - Kibu / Clínica*", 'ai:reply1:0']], $this->sent);
    }

    public function testWithoutAMembershipNameTheAiUsersNameSignsWithTheWorkspace(): void
    {
        $this->assistant($this->world(['agentName' => '', 'aiUserName' => 'IA *Kibu*']))->reply($this->reply('completed', 'Oi'));
        $this->assistant($this->world(['agentName' => '', 'aiUserName' => '']))->reply($this->reply('completed', 'Oi'));
        $this->assertSame([["Oi\n\n*IA Kibu / Clínica*", 'ai:reply1:0'], ["Oi\n\n*Clínica*", 'ai:reply1:0']], $this->sent);
    }

    public function testATurnTypedInTheAppStaysInTheApp(): void
    {
        $this->assistant($this->world(['source' => (object) []]))->reply($this->reply('completed', 'Oi'));
        $this->assertSame([], $this->sent);
    }

    public function testAnotherPersonsNumberNeverGetsTheAnswer(): void
    {
        $this->assistant($this->world(['subscriptionUser' => 'someoneElse']))->reply($this->reply('completed', 'Oi'));
        $this->assistant($this->world(['sessionOwner' => 'someoneElse']))->reply($this->reply('completed', 'Oi'));
        $this->assistant($this->world(['subscriptionStatus' => 'stopped']))->reply($this->reply('completed', 'Oi'));
        $this->assistant($this->world(['subscriptionAccount' => 'otherAccount']))->reply($this->reply('completed', 'Oi'));
        $this->assertSame([], $this->sent);
    }

    public function testNothingGoesOutOnceTheWindowClosed(): void
    {
        $this->assistant($this->world(['lastInboundAt' => gmdate('Y-m-d H:i:s', time() - 90000)]))->reply($this->reply('completed', 'Oi'));
        $this->assertSame([], $this->sent);
    }

    public function testOutOfCreditsAndFailuresSaySoAndCancelledSaysNothing(): void
    {
        $this->assistant($this->world())->reply($this->reply('blocked', 'This request was stopped because there are not enough AI credits.'));
        $this->assistant($this->world())->reply($this->reply('cancelled', 'This request was stopped or replaced by a newer mention.'));
        $this->assistant($this->world())->reply($this->reply('failed', 'This request could not finish.'));
        $this->assertSame([
            ["⚠️ Os créditos de IA deste espaço acabaram. Peça a um administrador para recarregar no Monostax.\n\n*✦ IA - Kibu / Clínica*", 'ai:reply1:0'],
            ["⚠️ Não consegui terminar esse pedido. Tente de novo daqui a pouco.\n\n*✦ IA - Kibu / Clínica*", 'ai:reply1:0'],
        ], $this->sent);
    }

    public function testOnlyThePersonsOwnWhatsAppTurnInTheirMainChatCanSwitchWorkspace(): void
    {
        $turn = $this->assistant($this->world())->turn('user1', 'source1');
        $this->assertSame(['text' => 'Qual o faturamento?', 'messageId' => 501], ['text' => $turn['text'], 'messageId' => $turn['messageId']]);
        $this->assertSame('sub1', $turn['subscription']->getId());
        $this->assertNull($this->assistant($this->world())->turn('someoneElse', 'source1'));
        $this->assertNull($this->assistant($this->world(['source' => (object) []]))->turn('user1', 'source1'));
        $this->assertNull($this->assistant($this->world(['primarySlot' => null]))->turn('user1', 'source1'));
        $this->assertNull($this->assistant($this->world(['subscriptionStatus' => 'stopped']))->turn('user1', 'source1'));
        $this->assertNull($this->assistant($this->world(['lastInboundAt' => gmdate('Y-m-d H:i:s', time() - 90000)]))->turn('user1', 'source1'));
    }

    public function testAVeryLongAnswerEndsWithALinkToTheChat(): void
    {
        $long = implode("\n\n", array_fill(0, 5, str_repeat('a', 3000)));
        $this->assistant($this->world())->reply($this->reply('completed', $long));
        $this->assertCount(3, $this->sent);
        $this->assertStringEndsWith("👉 A resposta continua no Monostax: https://chat.test/app/accounts/7/ai-sessions/session1\n\n*✦ IA - Kibu / Clínica*",
            $this->sent[2][0]);
        $this->assertStringEndsWith("\n\n*✦ IA - Kibu / Clínica*", $this->sent[0][0]);
        foreach ($this->sent as [$text]) $this->assertLessThanOrEqual(4096, mb_strlen($text));
        $this->assertSame('ai:reply1:2', $this->sent[2][1]);
    }

    /** @param array<string, mixed> $values */
    private function entity(string $id, array $values): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(fn (string $name) => $values[$name] ?? null);
        return $entity;
    }

    private function note(string $id, string $parentId, object $data, string $post = 'Qual o faturamento?', string $createdById = 'user1'): Note
    {
        $note = $this->createMock(Note::class);
        $note->method('getId')->willReturn($id);
        $note->method('getParentType')->willReturn('AiSession');
        $note->method('getParentId')->willReturn($parentId);
        $note->method('getData')->willReturn($data);
        $note->method('getPost')->willReturn($post);
        $note->method('getCreatedById')->willReturn($createdById);
        return $note;
    }

    private function reply(string $status, string $post): Note
    {
        return $this->note('reply1', 'session1', (object) ['opportunityStreamAgent' => (object) [
            'status' => $status, 'sourceNoteId' => 'source1', 'aiAgentMembershipId' => 'kibu',
        ]], $post, 'aiUser');
    }

    /** @param array<string, mixed> $overrides */
    private function world(array $overrides = []): array
    {
        $source = $this->note('source1', 'session1', $overrides['source'] ?? (object) [
            'aiSessionChannel' => 'whatsapp', 'whatsAppSubscriptionId' => 'sub1', 'whatsAppMessageId' => 501,
        ]);
        return [
            'Note' => $source,
            'ChatwootAccountUserMembership' => $this->entity('kibu', ['name' => $overrides['agentName'] ?? '✦ IA - Kibu']),
            'User' => $this->entity('aiUser', ['name' => $overrides['aiUserName'] ?? 'IA - Kibu']),
            'AiSession' => $this->entity('session1', ['assignedUserId' => $overrides['sessionOwner'] ?? 'user1', 'chatwootAccountId' => 'account1',
                'primarySlot' => array_key_exists('primarySlot', $overrides) ? $overrides['primarySlot'] : 'main']),
            'WhatsAppNotificationSubscription' => $this->entity('sub1', [
                'status' => $overrides['subscriptionStatus'] ?? 'active', 'userId' => $overrides['subscriptionUser'] ?? 'user1',
                'chatwootAccountId' => $overrides['subscriptionAccount'] ?? 'account1', 'phone' => '+5591999990000',
                'lastInboundAt' => $overrides['lastInboundAt'] ?? gmdate('Y-m-d H:i:s', time() - 60),
            ]),
        ];
    }

    /** @param array<string, object> $world */
    private function assistant(array $world): Assistant
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn (string $type, string $id) => $world[$type] ?? null);
        $avisos = $this->createMock(Avisos::class);
        $avisos->method('text')->willReturnCallback(function ($subscription, string $kind, string $text, $card = null, ?string $key = null) {
            $this->sent[] = [$text, $key];
            return null;
        });
        $workspaces = $this->createMock(Workspaces::class);
        $workspaces->method('of')->willReturn(['language' => 'pt_BR', 'frozen' => false, 'name' => 'Clínica']);
        $workspaces->method('sessionLink')->willReturnCallback(fn ($subscription, string $id) => "https://chat.test/app/accounts/7/ai-sessions/{$id}");
        return new Assistant($em, $this->createMock(ActorScope::class), $avisos, $workspaces, $this->createMock(Log::class));
    }
}
