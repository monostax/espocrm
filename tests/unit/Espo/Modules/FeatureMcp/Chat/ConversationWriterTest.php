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

use Espo\Modules\FeatureMcp\Tools\Chat\ChatAccess;
use Espo\Modules\FeatureMcp\Tools\Chat\ChatClient;
use Espo\Modules\FeatureMcp\Tools\Chat\ConversationReader;
use Espo\Modules\FeatureMcp\Tools\Chat\ConversationWriter;
use Espo\Tools\OAuthServer\CurrentClient;
use PHPUnit\Framework\TestCase;

class ConversationWriterTest extends TestCase
{
    private const array TEMPLATE = [
        'name' => 'retorno', 'language' => 'pt_BR', 'category' => 'UTILITY', 'status' => 'APPROVED',
        'components' => [['type' => 'BODY', 'text' => 'Oi {{1}}, podemos falar?']],
    ];

    /** @var list<array{string, string, ?array}> */
    private array $calls = [];

    public function testTextReplyIsTaggedWithTheApp(): void
    {
        $writer = $this->writer(['/conversations/7' => ['inbox_id' => 3, 'can_reply' => true]]);

        $result = $writer->reply($this->access(), 7, 'Olá!', null);

        $this->assertSame('sent', $result['status']);
        $this->assertSame(['POST', '/conversations/7/messages', [
            'message_type' => 'outgoing', 'private' => false, 'content_attributes' => ['sent_via_app' => 'Claude'], 'content' => 'Olá!',
        ]], end($this->calls));
    }

    public function testOutsideTheWindowNeedsATemplate(): void
    {
        $writer = $this->writer([
            '/conversations/7' => ['inbox_id' => 3, 'can_reply' => false],
            '/inboxes/3' => ['message_templates' => [self::TEMPLATE]],
        ]);

        $result = $writer->reply($this->access(), 7, 'Olá!', null);

        $this->assertSame('not_applied', $result['status']);
        $this->assertSame('outside_window', $result['problem']);
        $this->assertSame(['retorno'], $result['templates']);
        $this->assertSame([], array_filter($this->calls, fn ($call) => $call[0] === 'POST'));
    }

    public function testTemplateReplySendsTemplateParams(): void
    {
        $writer = $this->writer([
            '/conversations/7' => ['inbox_id' => 3, 'can_reply' => false],
            '/inboxes/3' => ['message_templates' => [self::TEMPLATE]],
        ]);

        $result = $writer->reply($this->access(), 7, null, ['name' => 'retorno', 'body' => ['1' => 'Ana']]);

        $this->assertSame('sent', $result['status']);
        $post = end($this->calls);
        $this->assertSame('Oi Ana, podemos falar?', $post[2]['content']);
        $this->assertSame('retorno', $post[2]['template_params']['name']);
        $this->assertSame(['sent_via_app' => 'Claude'], $post[2]['content_attributes']);
    }

    public function testLabelsAddAndRemove(): void
    {
        $writer = $this->writer(['/conversations/7' => ['labels' => ['vip', 'lead'], 'status' => 'open']]);

        $result = $writer->act($this->access(), 7, ['addLabels' => ['quente'], 'removeLabels' => ['lead']]);

        $this->assertSame(['vip', 'quente'], $result['changes']['labels']['after']);
        $this->assertSame(['POST', '/conversations/7/labels', ['labels' => ['vip', 'quente']]], end($this->calls));
    }

    public function testNoteMentionsTeammates(): void
    {
        $writer = $this->writer(['/agents' => [['id' => 12, 'name' => 'Ana Souza']]]);

        $writer->note($this->access(), 7, 'pode ver?', [12]);

        $this->assertSame('[@Ana Souza](mention://user/12/Ana%20Souza) pode ver?', end($this->calls)[2]['content']);
        $this->assertTrue(end($this->calls)[2]['private']);
    }

    /** @param array<string, array<mixed>> $gets */
    private function writer(array $gets): ConversationWriter
    {
        $client = $this->createMock(ChatClient::class);
        $client->method('get')->willReturnCallback(function ($access, string $path) use ($gets) {
            $this->calls[] = ['GET', $path, null];

            return $gets[$path] ?? [];
        });
        $client->method('post')->willReturnCallback(function ($access, string $path, array $body) {
            $this->calls[] = ['POST', $path, $body];

            return ['id' => 99, 'status' => 'sent'];
        });

        $current = new CurrentClient();
        $current->set('Claude');

        return new ConversationWriter($client, $current, new ConversationReader($client));
    }

    private function access(): ChatAccess
    {
        return new ChatAccess(6, 'Acme', 5, 'http://chat', 'secret');
    }
}
