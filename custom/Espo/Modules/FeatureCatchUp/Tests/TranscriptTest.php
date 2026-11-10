<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCatchUp\Services\Transcript;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class TranscriptTest extends TestCase
{
    private const AI_USER = 96;

    private function entity(array $values, string $id = 'id'): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        $entity->method('getId')->willReturn($id);
        $entity->method('getLinkMultipleIdList')->willReturn(['team']);
        return $entity;
    }

    /** @return array{Transcript, Entity} the transcript and the probe message its ACL check sees */
    private function transcript(ChatwootApiClient $api, bool $readable = true): array
    {
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkField')->willReturn(true);
        $acl->method('checkEntityRead')->willReturn($readable);
        $probe = $this->createMock(Entity::class);
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn([(string) self::AI_USER]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('query')->willReturn($statement);
        $em = $this->createMock(EntityManager::class);
        $em->method('getNewEntity')->willReturn($probe);
        $em->method('getPDO')->willReturn($pdo);
        $em->method('getEntityById')->willReturnCallback(fn ($type) => match ($type) {
            'ChatwootAccount' => $this->entity(['apiKey' => 'key', 'platformId' => 'platform', 'chatwootAccountId' => 12]),
            'ChatwootPlatform' => $this->entity(['backendUrl' => 'http://chatwoot']),
        });
        return [new Transcript($em, $acl, $api), $probe];
    }

    private function conversations(): array
    {
        return ['conv' => $this->entity(['chatwootAccountId' => 'account', 'chatwootConversationId' => 2775], 'conv')];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-05 15:00:00', new DateTimeZone('America/Sao_Paulo'));
    }

    private static function page(array $messages, ?array $contact = null): array
    {
        return ['contact' => $contact, 'messages' => $messages];
    }

    private static function message(int $id, int $type, ?array $sender, string $content = 'text', array $extra = []): array
    {
        return $extra + ['id' => $id, 'message_type' => $type, 'content' => $content, 'private' => false, 'sender' => $sender,
            'created_at' => 1793896376 + $id];
    }

    public function testReadsTheWholeConversationFromChatwootInTheReadersTime(): void
    {
        $staff = ['type' => 'user', 'id' => 95, 'name' => 'Erivânia Menezes'];
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::once())->method('getConversationMessagesPage')->with('http://chatwoot', 'key', 12, 2775, null)->willReturn(self::page([
            self::message(1, 0, ['type' => 'contact', 'id' => 7, 'name' => 'Samuel'], 'E qual o valor da consulta?'),
            self::message(2, 2, null, 'Atribuído a IA'),
            self::message(3, 1, $staff, 'O valor da consulta hoje é R$ 800,00.'),
            self::message(4, 1, null, 'Tenho dia 12/11'),
            self::message(5, 1, ['type' => 'user', 'id' => self::AI_USER, 'name' => 'IA (Assistente Erivania)'], 'Posso ajudar?'),
            self::message(6, 1, $staff, 'Cliente VIP', ['private' => true]),
            self::message(7, 1, ['type' => 'agent_bot', 'id' => 1, 'name' => 'Bot'], 'Olá'),
            self::message(8, 0, ['type' => 'contact', 'id' => 7, 'name' => 'Samuel'], '', ['attachments' => [self::file(81, 'image')]]),
        ], ['id' => 7, 'name' => 'Samuel', 'identifier' => null]));
        [$transcript, $probe] = $this->transcript($api);
        $probe->expects(self::once())->method('set')->with(['conversationId' => 'conv', 'teamsIds' => ['team']]);

        $evidence = $transcript->read($this->conversations(), $this->now())['evidence'];

        self::assertSame([8, 7, 6, 5, 4, 3, 1], array_column($evidence, 'messageId'), 'newest first, activity left out');
        $by = array_column($evidence, null, 'messageId');
        self::assertSame(['customer', 'staff', 'outside', 'ai', 'internal', 'ai', 'customer'],
            array_map(fn ($id) => $by[$id]['sentBy'], [1, 3, 4, 5, 6, 7, 8]));
        self::assertSame(['incoming', 'outgoing', 'note'], [$by[1]['kind'], $by[3]['kind'], $by[6]['kind']]);
        self::assertSame([null, 'Erivânia Menezes', null], [$by[1]['author'], $by[3]['author'], $by[4]['author']], 'only our side is named');
        self::assertSame('2026-11-05T13:32:57-03:00', $by[1]['at'], 'times are the reader\'s, with the offset');
        self::assertSame('[attachment 81: image]', $by[8]['text']);
        self::assertSame([['id' => '81', 'fileType' => 'image', 'url' => 'https://chat.test/rails/active_storage/blobs/redirect/81/f', 'size' => 1200]],
            $by[8]['attachments']);
        self::assertArrayNotHasKey('attachments', $by[3], 'a message without files offers none');
        self::assertSame(['3', 2775, 12], [$by[3]['id'], $by[3]['conversationId'], $by[3]['accountId']]);
    }

    private static function file(int $id, string $type, array $extra = []): array
    {
        return $extra + ['id' => $id, 'file_type' => $type, 'data_url' => "https://chat.test/rails/active_storage/blobs/redirect/$id/f", 'file_size' => 1200];
    }

    public function testImagesAndDocumentsAreOfferedAndVoiceNotesReadAsTranscripts(): void
    {
        $staff = ['type' => 'user', 'id' => 95, 'name' => 'Erivânia Menezes'];
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getConversationMessagesPage')->willReturn(self::page([
            self::message(1, 0, null, 'Segue o comprovante', ['attachments' => [self::file(11, 'file'), self::file(12, 'video')]]),
            self::message(2, 1, $staff, '', ['attachments' => [self::file(21, 'audio', ['transcribed_text' => 'Pode ser dia 11?'])]]),
            self::message(3, 0, null, 'Quero marcar para sexta', ['attachments' => [self::file(31, 'audio')]]),
            self::message(4, 1, null, '', ['attachments' => [self::file(41, 'audio'), self::file(42, 'image', ['data_url' => ''])]]),
        ]));
        [$transcript] = $this->transcript($api);
        $by = array_column($transcript->read($this->conversations(), $this->now())['evidence'], null, 'messageId');

        self::assertSame('Segue o comprovante [attachment 11: file] [attachment 12: video]', $by[1]['text']);
        self::assertSame(['11'], array_column($by[1]['attachments'], 'id'), 'video stays a marker');
        self::assertSame('[attachment 21: audio, transcript: Pode ser dia 11?]', $by[2]['text']);
        self::assertArrayNotHasKey('attachments', $by[2], 'a transcribed voice note is read as text');
        self::assertArrayNotHasKey('attachments', $by[3], 'an incoming voice note\'s transcript is the message text');
        self::assertSame(['41'], array_column($by[4]['attachments'], 'id'), 'audio nobody transcribed is offered; a file without a link is not');
    }

    public function testRepliesNameTheQuotedMessageAndReactionsSayWhoReacted(): void
    {
        $customer = ['type' => 'contact', 'id' => 7, 'name' => 'Samuel'];
        $staff = ['type' => 'user', 'id' => 95, 'name' => 'Erivânia Menezes'];
        $page = fn (int $from, int $count) => array_map(fn ($id) => self::message($id, 1, $staff, "Mensagem $id"), range($from, $from + $count - 1));
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getConversationMessagesPage')->willReturnCallback(fn ($url, $key, $account, $conversation, $before) => self::page(match ($before) {
            null => [...$page(43, 18),
                self::message(61, 0, $customer, 'pode ser', ['content_attributes' => ['in_reply_to' => 2]]),
                self::message(62, 0, $customer, 'esse mesmo', ['content_attributes' => json_encode(['in_reply_to' => 61])]),
            ],
            43 => [...$page(23, 20)],
            // The page that ends right after the quoted message, read only to quote it.
            3 => [self::message(1, 0, $customer, 'Oi'), self::message(2, 1, $staff, 'Fica R$ 4.200 à vista no Pix')],
            default => [],
        }));
        [$transcript] = $this->transcript($api);
        $evidence = $transcript->read($this->conversations(), $this->now())['evidence'];
        $by = array_column($evidence, null, 'messageId');

        self::assertCount(Transcript::LIMIT, $evidence);
        self::assertArrayNotHasKey(2, $by, 'the quoted price is older than the messages sent');
        self::assertSame(['2', 'Fica R$ 4.200 à vista no Pix'], [$by[61]['replyTo'], $by[61]['replyText']], 'so its text travels with the reply');
        self::assertSame('61', $by[62]['replyTo'], 'a double-encoded content_attributes is read too');
        self::assertArrayNotHasKey('replyText', $by[62], 'a quoted message among those sent is not repeated');
        self::assertArrayNotHasKey('replyTo', $by[60], 'a message that quotes nothing carries no reply field');

        $older = $this->createMock(ChatwootApiClient::class);
        $older->method('getConversationMessagesPage')->willReturn(self::page([self::message(2, 1, $staff, 'Fica R$ 4.200 à vista no Pix', [
            'reactions' => [['emoji' => '👍', 'actor_type' => 'contact', 'actor_name' => 'Samuel'],
                ['emoji' => '🙏', 'actor_type' => 'business', 'actor_name' => 'IA', 'performed_by_id' => self::AI_USER],
                ['emoji' => '❤️', 'actor_type' => 'business', 'actor_name' => 'Erivânia', 'performed_by_id' => 95], ['emoji' => '']],
        ])]));
        [$transcript] = $this->transcript($older);
        self::assertSame([
            ['emoji' => '👍', 'sentBy' => 'customer', 'author' => 'Samuel'],
            ['emoji' => '🙏', 'sentBy' => 'ai', 'author' => 'IA'],
            ['emoji' => '❤️', 'sentBy' => 'staff', 'author' => 'Erivânia'],
        ], $transcript->read($this->conversations(), $this->now())['evidence'][0]['reactions']);
    }

    public function testOlderPagesAreReadUntilChatwootRunsOutOrTheLimitIsReached(): void
    {
        $page = fn (int $from, int $count) => array_map(fn ($id) => self::message($id, 0, null), range($from, $from + $count - 1));
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::exactly(2))->method('getConversationMessagesPage')
            ->willReturnCallback(fn ($url, $key, $account, $conversation, $before) => self::page($before === null ? $page(21, 20) : $page(16, 5)));
        [$transcript] = $this->transcript($api);
        $evidence = $transcript->read($this->conversations(), $this->now())['evidence'];
        self::assertSame(range(40, 16), array_column($evidence, 'messageId'));

        $befores = [];
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getConversationMessagesPage')->willReturnCallback(function ($url, $key, $account, $conversation, $before) use (&$befores, $page) {
            $befores[] = $before;
            return self::page($page(($before ?? 1000) - 20, 20));
        });
        [$transcript] = $this->transcript($api);
        $evidence = $transcript->read($this->conversations(), $this->now())['evidence'];
        self::assertSame([null, 980], $befores, 'each page ends before the previous page\'s oldest message');
        self::assertCount(Transcript::LIMIT, $evidence);
        self::assertSame(999, $evidence[0]['messageId']);
    }

    public function testConversationWhoseMessagesTheReaderMayNotReadIsNotFetched(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::never())->method('getConversationMessagesPage');
        [$transcript] = $this->transcript($api, false);
        self::assertSame(['evidence' => [], 'participants' => []], $transcript->read($this->conversations(), $this->now()));
    }

    public function testChatwootFailureFailsTheAnalysisInsteadOfJudgingWithoutTheConversation(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getConversationMessagesPage')->willThrowException(new Error('Failed to get conversation messages from Chatwoot: HTTP 502'));
        [$transcript] = $this->transcript($api);
        $this->expectException(ServiceUnavailable::class);
        $transcript->read($this->conversations(), $this->now())['evidence'];
    }

    public function testInAGroupTheTeamWritesForTheBusinessAndUnlabeledPeopleAreAskedAbout(): void
    {
        $doctor = ['type' => 'contact', 'id' => 20094, 'name' => 'Drª Michele', 'custom_attributes' => ['em_dia_tipo' => 'Equipe']];
        $patient = ['type' => 'contact', 'id' => 20289, 'name' => 'Jessyka Souza', 'custom_attributes' => []];
        $nutri = ['type' => 'contact', 'id' => 20110, 'name' => 'Nutricionista Sabrina', 'custom_attributes' => ['em_dia_tipo' => 'Algo que alguém escreveu']];
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getConversationMessagesPage')->willReturn(self::page([
            self::message(1, 0, $patient, 'Posso tomar antes do treino?'),
            self::message(2, 0, $doctor, 'Pode sim, Jessyka.'),
            self::message(3, 0, $nutri, 'Mande foto do prato'),
            self::message(4, 1, null, 'Combinado'),
        ], ['id' => 1984, 'name' => 'Jessyka Levitar 3+ (Grupo)', 'identifier' => '120363429480885746@g.us', 'custom_attributes' => []]));
        [$transcript] = $this->transcript($api);
        ['evidence' => $evidence, 'participants' => $participants] = $transcript->read($this->conversations(), $this->now());
        $by = array_column($evidence, null, 'messageId');

        self::assertSame(['customer', 'staff', 'customer', 'outside'], [$by[1]['sentBy'], $by[2]['sentBy'], $by[3]['sentBy'], $by[4]['sentBy']],
            'the doctor labeled Equipe answers for the business');
        self::assertSame(['Jessyka Souza', 'Drª Michele', '20289'], [$by[1]['author'], $by[2]['author'], $by[1]['participantId']]);
        self::assertSame([['1984', true, null, true], ['20289', false, null, true], ['20094', false, 'team', false], ['20110', false, null, false]],
            array_map(fn ($p) => [$p['id'], $p['group'], $p['label'], $p['classify']], $participants),
            'the group and unlabeled members are asked about; a value a person typed is never replaced');
        self::assertSame([12], array_values(array_unique(array_column($participants, 'accountId'))));
    }

    public function testADirectChatHasNoParticipantsToClassify(): void
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getConversationMessagesPage')->willReturn(self::page([
            self::message(1, 0, ['type' => 'contact', 'id' => 7, 'name' => 'Samuel', 'custom_attributes' => []], 'Oi'),
        ], ['id' => 7, 'name' => 'Samuel', 'identifier' => null, 'custom_attributes' => []]));
        [$transcript] = $this->transcript($api);
        ['evidence' => $evidence, 'participants' => $participants] = $transcript->read($this->conversations(), $this->now());
        self::assertSame([], $participants, 'a deal proves its own contact is the customer');
        self::assertSame([null, 'customer'], [$evidence[0]['author'], $evidence[0]['sentBy']]);
    }

    public function testRelabelAppliesNewClassesOnlyToPeopleWritingToUs(): void
    {
        $evidence = [
            ['id' => '1', 'sentBy' => 'customer', 'participantId' => '20094'],
            ['id' => '2', 'sentBy' => 'customer', 'participantId' => '20289'],
            ['id' => '3', 'sentBy' => 'internal', 'participantId' => null],
            ['id' => 'post', 'sentBy' => 'internal'],
        ];
        $relabeled = Transcript::relabel($evidence, ['20094' => 'team', '20289' => 'customer']);
        self::assertSame(['staff', 'customer', 'internal', 'internal'], array_column($relabeled, 'sentBy'));
    }
}
