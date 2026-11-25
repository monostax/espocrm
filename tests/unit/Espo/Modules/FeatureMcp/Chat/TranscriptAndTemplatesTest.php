<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace tests\unit\Espo\Modules\FeatureMcp\Chat;

use Espo\Modules\FeatureMcp\Tools\Chat\Templates;
use Espo\Modules\FeatureMcp\Tools\Chat\Transcript;
use PHPUnit\Framework\TestCase;

class TranscriptAndTemplatesTest extends TestCase
{
    public function testSpeakersAndText(): void
    {
        $this->assertSame('customer', Transcript::speaker(['message_type' => 0]));
        $this->assertSame('team (Ana)', Transcript::speaker(['message_type' => 1, 'sender' => ['name' => 'Ana']]));
        $this->assertSame('internal note by Ana', Transcript::speaker(['message_type' => 1, 'private' => true, 'sender' => ['name' => 'Ana']]));
        $this->assertSame('team (sent from the phone)', Transcript::speaker(['message_type' => 1]));
        $this->assertNull(Transcript::speaker(['message_type' => 2]));

        $text = Transcript::text([
            'content' => "Olá\n  mundo",
            'content_attributes' => json_encode(['deleted' => true]),
            'attachments' => [['file_type' => 'audio', 'transcribed_text' => 'quero o plano'], ['file_type' => 'image', 'extension' => 'jpg']],
        ]);
        $this->assertSame('[Mensagem excluída] Olá mundo [voice message: quero o plano] [image attachment jpg, content not shown]', $text);
        $snippet = Transcript::snippet(str_repeat('x', 200) . ' Ação do plano básico', 'acao');
        $this->assertStringStartsWith('…', $snippet);
        $this->assertStringEndsWith('Ação do plano básico', $snippet);
    }

    public function testFormatKeepsNewestWithinBudget(): void
    {
        $messages = [];

        for ($i = 1; $i <= 30; $i++) {
            $messages[] = ['id' => $i, 'message_type' => $i % 2, 'content' => str_repeat('a', 900), 'created_at' => 1764000000 + $i];
        }

        $out = Transcript::format($messages, null, 'America/Sao_Paulo');

        $this->assertLessThan(30, count($out['lines']));
        $this->assertStringContainsString('#30 ', end($out['lines']));
        $this->assertSame(30 - count($out['lines']) + 1, $out['earlierBefore']);
    }

    public function testTemplateBuild(): void
    {
        $template = [
            'name' => 'retorno', 'language' => 'pt_BR', 'category' => 'UTILITY', 'status' => 'APPROVED',
            'components' => [
                ['type' => 'HEADER', 'format' => 'IMAGE'],
                ['type' => 'BODY', 'text' => 'Oi {{1}}, seu pedido {{2}} chegou.'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'QUICK_REPLY', 'text' => 'Ok'],
                    ['type' => 'URL', 'text' => 'Ver', 'url' => 'https://x.com/p/{{1}}'],
                ]],
            ],
        ];

        $this->assertSame([$template], Templates::approved(['message_templates' => [$template, ['name' => 'x', 'status' => 'PENDING']]]));
        $this->assertSame(['1', '2'], Templates::describe($template)['bodyParameters']);

        $missing = Templates::build($template, ['body' => ['1' => 'Ana']]);
        $this->assertFalse($missing['ok']);

        $built = Templates::build($template, [
            'body' => ['1' => 'Ana', '2' => '#42'],
            'header' => ['mediaUrl' => 'https://cdn.x.com/a.jpg'],
            'buttons' => ['', 'abc'],
        ]);
        $this->assertTrue($built['ok']);
        $this->assertSame('Oi Ana, seu pedido #42 chegou.', $built['content']);
        $this->assertSame('{"name":"retorno","category":"UTILITY","language":"pt_BR","processed_params":{"body":{"1":"Ana","2":"#42"},"header":{"media_url":"https:\/\/cdn.x.com\/a.jpg","media_type":"image"},"buttons":[null,{"type":"url","parameter":"abc"}]}}', json_encode($built['params']));
    }
}
