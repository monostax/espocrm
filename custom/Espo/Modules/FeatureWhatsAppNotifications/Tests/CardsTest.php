<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tests;

use Espo\Core\Utils\Config;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Avisos;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\CardImages;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Cards;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Workspaces;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;

/** Avisos messages have no buttons (the user, 2026-11-13): a card is text and the link that opens it in Em dia. */
class CardsTest extends TestCase
{
    private const LINK = 'https://chat.app.monostax.ai/app/accounts/6/catch-up?card=service:conv1';

    private function entity(array $values): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn((string) ($values['id'] ?? 'x'));
        $entity->method('get')->willReturnCallback(fn (string $name) => $values[$name] ?? null);
        return $entity;
    }

    private function cards(Avisos $avisos, array $config = [], ?string $png = null): Cards
    {
        $workspaces = $this->createMock(Workspaces::class);
        $workspaces->method('of')->willReturn(['language' => 'pt_BR']);
        $workspaces->method('cardLink')->willReturn(self::LINK);
        $configMock = $this->createMock(Config::class);
        $configMock->method('get')->willReturnCallback(fn (string $name) => $name === 'whatsAppNotifications' ? $config : null);
        $images = $this->createMock(CardImages::class);
        $images->method('url')->willReturn('https://app.monostax.ai/api/v1/WhatsAppNotifications/cardImage/card1.png');
        $images->method('render')->willReturn($png);
        return new Cards($avisos, $workspaces, new Settings($configMock), $images);
    }

    private function card(): Entity
    {
        return $this->entity(['id' => 'card1', 'lane' => 'service', 'subjectId' => 'conv1', 'verdict' => 'customer_waiting',
            'contactName' => 'Sara Ferreira', 'headline' => 'Analisar vídeos e responder Sara', 'context' => 'Sara enviou dois vídeos.']);
    }

    public function testInsideTheWindowACardIsTextWithItsEmDiaLink(): void
    {
        $subscription = $this->entity(['id' => 'sub1', 'lastInboundAt' => gmdate('Y-m-d H:i:s')]);
        $card = $this->card();
        $avisos = $this->createMock(Avisos::class);
        $avisos->expects($this->never())->method('buttons');
        $avisos->expects($this->never())->method('template');
        $avisos->expects($this->once())->method('text')->with($subscription, 'card',
            $this->callback(fn (string $text) => str_contains($text, 'Sara Ferreira') && str_ends_with($text, '👉 Abrir card: ' . self::LINK)),
            $card, 'k1')->willReturn(null);
        $this->cards($avisos)->show($subscription, $card, 'card', 'k1');
    }

    /** The user's format (2026-11-13): the picture and two lines, the headline sentence and the link. Nothing more. */
    private const CAPTION = "🔥 *Sara Ferreira* está esperando resposta há mais de *algum tempo*.\n\n" . self::LINK . '&utm_source=whatsapp';

    /** Outside the window the UTILITY template: Meta files the bare two lines as MARKETING. */
    public function testOutsideTheWindowTheUtilityTemplateCarriesTheLinkWithItsUtm(): void
    {
        $subscription = $this->entity(['id' => 'sub1', 'lastInboundAt' => null]);
        $card = $this->card();
        $avisos = $this->createMock(Avisos::class);
        $avisos->expects($this->never())->method('buttons');
        $link = self::LINK . '&utm_source=whatsapp';
        $avisos->expects($this->once())->method('template')->with($subscription, 'card', 'monostax_catchup_card_link',
            "🔥 *Sara Ferreira* está esperando resposta há mais de *algum tempo*.\n\nAbra o card no Em dia: {$link}\n\nA sugestão de resposta já está pronta lá.",
            ['Sara Ferreira', 'algum tempo', $link], [], $card, null,
            'https://app.monostax.ai/api/v1/WhatsAppNotifications/cardImage/card1.png')->willReturn(null);
        $this->cards($avisos, ['cardImage' => true, 'cardTemplate' => 'monostax_catchup_card_link'])->show($subscription, $card, 'card');
    }

    public function testInsideTheWindowTheCardIsThePictureWithThatCaption(): void
    {
        $subscription = $this->entity(['id' => 'sub1', 'lastInboundAt' => gmdate('Y-m-d H:i:s')]);
        $card = $this->card();
        $avisos = $this->createMock(Avisos::class);
        $avisos->expects($this->never())->method('buttons');
        $avisos->expects($this->never())->method('template');
        $avisos->expects($this->once())->method('image')->with($subscription, 'card', self::CAPTION, 'PNG', $card, 'k1')->willReturn(null);
        $this->cards($avisos, ['cardImage' => true], 'PNG')->show($subscription, $card, 'card', 'k1');
    }

    public function testWhenThePictureCantBeDrawnTheCaptionGoesAlone(): void
    {
        $subscription = $this->entity(['id' => 'sub1', 'lastInboundAt' => gmdate('Y-m-d H:i:s')]);
        $card = $this->card();
        $avisos = $this->createMock(Avisos::class);
        $avisos->expects($this->never())->method('image');
        $avisos->expects($this->once())->method('text')->with($subscription, 'card', self::CAPTION, $card, null)->willReturn(null);
        $this->cards($avisos, ['cardImage' => true], null)->show($subscription, $card, 'card');
    }

    public function testATapFromAnOlderMessageAnswersWithTheCardLink(): void
    {
        $subscription = $this->entity(['id' => 'sub1']);
        $card = $this->card();
        $avisos = $this->createMock(Avisos::class);
        $avisos->expects($this->never())->method('buttons');
        $sent = [];
        $avisos->method('text')->willReturnCallback(function ($s, $kind, string $text) use (&$sent) {
            $sent[] = $text;
            return null;
        });
        $cards = $this->cards($avisos);
        $cards->open($subscription, $card);
        $cards->later($subscription, $card);
        $cards->then($subscription, '✅ Marcado como resolvido.', 2);
        $this->assertStringContainsString(self::LINK, $sent[0]);
        $this->assertStringContainsString(self::LINK, $sent[1]);
        $this->assertStringEndsWith('Escreva *em dia* para ver o próximo.', $sent[2]);
    }
}
