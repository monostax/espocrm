<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Tests;

use Espo\ORM\Entity;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureAutomation\Classes\AutomationActions\OpenConversationWithNote;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class OpenConversationWithNoteTest extends TestCase
{
    private const ACCOUNT_KEY = 'integration-account-key';
    private const PERSON_TOKEN = 'jonathan-own-token';

    /** @var list<array{string, string, string, string, ?array}> */
    private array $requests = [];
    private bool $findOrCreateCalled = false;

    private function entity(string $type, string $id, array $values): Entity
    {
        $entity = $this->createStub(Entity::class);
        $entity->method('getEntityType')->willReturn($type);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        $entity->method('hasAttribute')->willReturnCallback(fn ($field) => array_key_exists($field, $values));

        return $entity;
    }

    /** @param array<string, list<array{array<string, mixed>, ?Entity}>> $lookups repository => [where, result] */
    private function entityManager(array $byId, array $lookups): EntityManager
    {
        $em = $this->createStub(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $byId[$type . '/' . $id] ?? null);
        $em->method('getRDBRepository')->willReturnCallback(function (string $type) use ($lookups) {
            $where = [];
            $builder = $this->createStub(RDBSelectBuilder::class);
            $builder->method('where')->willReturnCallback(function ($clause) use (&$where, $builder) {
                $where = $clause;

                return $builder;
            });
            $builder->method('order')->willReturnSelf();
            $builder->method('findOne')->willReturnCallback(function () use (&$where, $lookups, $type) {
                foreach ($lookups[$type] ?? [] as [$match, $result]) {
                    if ($match === $where) {
                        return $result;
                    }
                }

                return null;
            });
            $repo = $this->createStub(RDBRepository::class);
            $repo->method('where')->willReturnCallback(fn ($clause) => $builder->where($clause));

            return $repo;
        });

        return $em;
    }

    private function action(array $params, bool $linked = true, bool $optedOut = false): OpenConversationWithNote
    {
        $contact = $this->entity('Contact', 'c1', ['whatsAppOptedOut' => $optedOut]);
        $inbox = $this->entity('ChatwootInbox', 'inbox1', ['chatwootAccountId' => 'acc1']);
        $account = $this->entity('ChatwootAccount', 'acc1', ['platformId' => 'plat1']);
        $membership = $this->entity('ChatwootAccountUserMembership', 'ai1', [
            'isAI' => true, 'chatwootAccountId' => 'acc1', 'chatwootUserId' => 'cwuAi', 'availableName' => 'IA',
        ]);
        $aiUser = $this->entity('ChatwootUser', 'cwuAi', ['chatwootUserId' => 108, 'name' => 'IA']);
        $jonathan = $this->entity('ChatwootUser', 'cwuJ', ['chatwootUserId' => 100, 'userAccessToken' => self::PERSON_TOKEN]);

        $em = $this->entityManager(
            [
                'Contact/c1' => $contact,
                'ChatwootAccount/acc1' => $account,
                'ChatwootAccountUserMembership/ai1' => $membership,
                'ChatwootUser/cwuAi' => $aiUser,
            ],
            [
                'ChatwootContact' => $linked
                    ? [[['contactId' => 'c1', 'chatwootAccountId' => 'acc1'], $this->entity('ChatwootContact', 'l1', ['chatwootContactId' => 52988])]]
                    : [],
                'ChatwootUser' => [[['assignedUserId' => 'uJonathan', 'platformId' => 'plat1'], $jonathan]],
            ],
        );

        $outbound = $this->createStub(JourneyWhatsAppOutbound::class);
        $outbound->method('isOptedOut')->willReturnCallback(fn (Entity $e) => (bool) $e->get('whatsAppOptedOut'));
        $outbound->method('resolveConnection')->willReturn([
            'platformUrl' => 'https://cw.test',
            'accountApiKey' => self::ACCOUNT_KEY,
            'externalAccountId' => 13,
            'externalInboxId' => 91,
            'channelType' => 'whatsappQrcode',
            'inbox' => $inbox,
        ]);
        $outbound->method('resolvePhones')->willReturn(['+5541999999999']);
        $outbound->method('displayName')->willReturn('Lead');

        $api = $this->createStub(ChatwootApiClient::class);
        $api->method('accountRequest')->willReturnCallback(function (...$args) {
            $this->requests[] = $args;

            return ['code' => 200, 'body' => ['id' => 77]];
        });
        $api->method('findOrCreateContact')->willReturnCallback(function () {
            $this->findOrCreateCalled = true;

            return ['id' => 600];
        });

        $this->params = $params;

        return new OpenConversationWithNote($outbound, $this->createStub(TenantGuard::class), $em, $api, $this->createStub(Log::class));
    }

    private array $params = [];

    private function context(): ActionContext
    {
        $deal = $this->entity('Opportunity', 'o1', ['contactId' => 'c1', 'assignedUserId' => 'uJonathan', 'tenantId' => 't1']);
        $actor = $this->createStub(User::class);
        $actor->method('getId')->willReturn('uJonathan');
        $actor->method('getUserName')->willReturn('jonathan');
        $automation = $this->entity('Automation', 'a1', []);

        return new ActionContext($deal, $this->entity('AutomationRunItem', 'i1', []), $automation, $automation, 'automation', $this->params, 't1', $actor);
    }

    public function testPostsPrivateNoteAsThePersonOnThePushedContactMentioningTheAi(): void
    {
        $this->action(['chatwootInboxId' => 'inbox1', 'aiAgentId' => 'ai1', 'note' => 'Novo lead do formulário', 'assignToOwner' => true])
            ->run($this->context());

        $this->assertCount(1, $this->requests);
        [$url, $token, $method, $path, $body] = $this->requests[0];
        $this->assertSame('https://cw.test', $url);
        $this->assertSame(self::PERSON_TOKEN, $token, 'never the integration key: the AI drops notes written by AI users');
        $this->assertSame('POST', $method);
        $this->assertSame('/api/v1/accounts/13/conversations', $path);
        $this->assertSame([
            'contact_id' => 52988,
            'inbox_id' => 91,
            'assignee_id' => 100,
            'message' => ['content' => '[@IA](mention://user/108/IA) Novo lead do formulário', 'private' => true],
        ], $body);
        $this->assertFalse($this->findOrCreateCalled);
    }

    public function testWithoutAssignOrMentionAndWithoutPushedContact(): void
    {
        $this->action(['chatwootInboxId' => 'inbox1', 'note' => 'Oi'], linked: false)->run($this->context());

        $body = $this->requests[0][4];
        $this->assertSame(['contact_id' => 600, 'inbox_id' => 91, 'message' => ['content' => 'Oi', 'private' => true]], $body);
        $this->assertTrue($this->findOrCreateCalled);
    }

    public function testOptedOutPersonGetsNothing(): void
    {
        $this->action(['chatwootInboxId' => 'inbox1', 'note' => 'Oi'], optedOut: true)->run($this->context());

        $this->assertSame([], $this->requests);
    }

    public function testMentionMarkupEncodesTheName(): void
    {
        $this->assertSame(
            '[@Monostax Integração](mention://user/99/Monostax%20Integra%C3%A7%C3%A3o)',
            OpenConversationWithNote::mentionMarkup(99, 'Monostax Integração'),
        );
    }
}
