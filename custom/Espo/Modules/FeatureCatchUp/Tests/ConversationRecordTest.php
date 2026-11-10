<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\ChatwootAccess;
use Espo\Modules\FeatureCatchUp\Services\ConversationRecord;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Records;
use Espo\Modules\FeatureCatchUp\Services\Transcript;
use Espo\Modules\FeatureCatchUp\Services\Workspace;
use Espo\Core\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class ConversationRecordTest extends TestCase
{
    private function entity(string $id, array $values = []): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        return $entity;
    }

    private function request(string $conversationId = '2775'): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(fn ($name) => $name === 'conversationId' ? $conversationId : '12');
        return $request;
    }

    private static function record(): array
    {
        return ['coveredAt' => '2026-11-05T14:22:00-03:00', 'agreed' => [
            ['key' => 'valor', 'value' => 'R$ 4.200 à vista no Pix', 'messageId' => '130', 'was' => [['value' => 'R$ 5.000', 'messageId' => 98], 'x']],
            ['key' => 'escopo', 'value' => 'Módulo A'],
            ['value' => 'sem chave'],
        ], 'promises' => [
            ['id' => 'contrato', 'side' => 'ours', 'text' => 'enviar o contrato', 'dueAt' => '2026-11-05T18:00:00-03:00', 'openedBy' => '130', 'status' => 'open'],
            ['id' => 'pix', 'side' => 'theirs', 'text' => 'mandar o comprovante', 'dueAt' => null, 'openedBy' => 'abc'],
            ['id' => 'proposta', 'side' => 'ours', 'text' => 'enviar a proposta', 'status' => 'kept', 'closedBy' => '120'],
        ]];
    }

    /**
     * @param array<string, ?array> $stored "type:id:scope" => record
     * @param list<string> $deals open deal ids linked to the conversation
     */
    private function service(array $stored, array $deals = ['deal', 'hidden'], bool $messagesReadable = true, bool $regular = true,
        bool $chatwootOpens = true): ConversationRecord
    {
        $user = $this->createMock(User::class);
        $user->method('isRegular')->willReturn($regular);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkEntityRead')->willReturnCallback(fn (Entity $entity) => $entity->getId() !== 'hidden');
        $conversation = $this->entity('conv');
        $entities = ['ChatwootConversation:conv' => $conversation, 'Opportunity:deal' => $this->entity('deal', ['name' => 'Ana - Retainer']),
            'Opportunity:hidden' => $this->entity('hidden', ['name' => 'Segredo'])];
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $entities["$type:$id"] ?? null);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($deals) {
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchColumn')->willReturn('conv');
            $statement->method('fetchAll')->willReturn(str_contains($sql, 'opportunity') ? $deals : []);
            return $statement;
        });
        $em->method('getPDO')->willReturn($pdo);
        $workspace = $this->createMock(Workspace::class);
        $workspace->method('account')->willReturn(['tenant' => $this->entity('tenant'), 'account' => $this->entity('account')]);
        $feed = $this->createMock(Feed::class);
        $feed->method('conversations')->willReturn(['conv' => $conversation, 'other' => $this->entity('other')]);
        $transcript = $this->createMock(Transcript::class);
        $transcript->method('readable')->willReturn($messagesReadable);
        $records = $this->createMock(Records::class);
        $records->method('load')->willReturnCallback(fn ($type, $id, $scope) => $stored["$type:$id:$scope"] ?? null);
        $access = $this->createMock(ChatwootAccess::class);
        $access->method('allows')->willReturn($chatwootOpens);
        return new ConversationRecord($em, $acl, $user, $workspace, $feed, $transcript, $records, $access);
    }

    public function testEachOpenDealShowsItsRecordUnderTheReadersScopeWithOnlyOpenPromises(): void
    {
        $scope = Records::scope(['other', 'conv']);
        $service = $this->service(["Opportunity:deal:$scope" => self::record(), 'Opportunity:hidden:' . $scope => self::record()]);

        $records = $service->read($this->request())['records'];

        self::assertCount(1, $records, 'a deal the reader may not read is not shown');
        self::assertSame(['type' => 'opportunity', 'id' => 'deal', 'name' => 'Ana - Retainer'], $records[0]['subject']);
        self::assertSame([
            ['key' => 'valor', 'value' => 'R$ 4.200 à vista no Pix', 'messageId' => '130', 'was' => [['value' => 'R$ 5.000', 'messageId' => '98']]],
            ['key' => 'escopo', 'value' => 'Módulo A', 'messageId' => null, 'was' => []],
        ], $records[0]['agreed']);
        self::assertSame([
            ['side' => 'ours', 'text' => 'enviar o contrato', 'dueAt' => '2026-11-05T18:00:00-03:00', 'messageId' => '130'],
            ['side' => 'theirs', 'text' => 'mandar o comprovante', 'dueAt' => null, 'messageId' => null],
        ], $records[0]['promises'], 'kept promises stay in the CRM');
        self::assertSame('2026-11-05T14:22:00-03:00', $records[0]['coveredAt']);
    }

    public function testARecordBuiltFromAnotherSetOfConversationsIsNotShown(): void
    {
        $service = $this->service(['Opportunity:deal:' . Records::scope(['conv']) => self::record()]);
        self::assertSame([], $service->read($this->request())['records']);
    }

    public function testAConversationWithoutADealShowsItsOwnRecord(): void
    {
        $service = $this->service(['ChatwootConversation:conv:' . Records::scope(['conv']) => self::record()], []);
        $records = $service->read($this->request())['records'];
        self::assertSame(['type' => 'conversation', 'id' => 'conv', 'name' => null], $records[0]['subject']);
    }

    public function testNothingIsShownWhenTheReaderMayNotReadTheMessages(): void
    {
        $scope = Records::scope(['other', 'conv']);
        $service = $this->service(["Opportunity:deal:$scope" => self::record()], ['deal'], false);
        self::assertSame(['records' => []], $service->read($this->request()));
    }

    public function testNothingIsShownWhenChatwootWontOpenTheConversationForTheReader(): void
    {
        $scope = Records::scope(['other', 'conv']);
        $service = $this->service(["Opportunity:deal:$scope" => self::record()], ['deal'], chatwootOpens: false);
        self::assertSame(['records' => []], $service->read($this->request()), 'the CRM ACL allowed it, Chatwoot did not');
    }

    public function testPortalUsersAndBadIdsAreRefused(): void
    {
        try {
            $this->service([], regular: false)->read($this->request());
            self::fail('a portal user reads no record');
        } catch (Forbidden) {
        }
        $this->expectException(BadRequest::class);
        $this->service([])->read($this->request('12a'));
    }
}
