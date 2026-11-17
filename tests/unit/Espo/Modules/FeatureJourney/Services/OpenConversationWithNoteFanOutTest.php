<?php

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\OpenConversationWithNote;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyTargetPeople;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

/**
 * An Account journey posts the private note in the conversation of each contact in
 * Contatos, with the note's placeholders filled for that contact.
 */
class OpenConversationWithNoteFanOutTest extends TestCase
{
    /** @var list<array<string, mixed>> request bodies posted to Chatwoot */
    private array $bodies = [];

    public function testAccountGetsANoteInEachContactsConversation(): void
    {
        $account = $this->entity('Account', 'acc1', ['name' => 'Elite']);
        $ana = $this->entity('Contact', 'c1', ['firstName' => 'Ana']);
        $out = $this->entity('Contact', 'c2', ['firstName' => 'Out', 'whatsAppOptedOut' => true]);
        $blank = $this->entity('Contact', 'c3', ['firstName' => '']);
        $nophone = $this->entity('Contact', 'c4', ['firstName' => 'Nophone']);
        $bia = $this->entity('Contact', 'c5', ['firstName' => 'Bia']);

        $this->action([$ana, $out, $blank, $nophone, $bia], ['c1' => 501, 'c5' => 505])->run(
            $this->context($account, ['chatwootInboxId' => 'inbox1', 'note' => 'Escreva para {{contact.firstName}}'])
        );

        $this->assertSame([
            ['contact_id' => 501, 'inbox_id' => 91, 'message' => ['content' => 'Escreva para Ana', 'private' => true]],
            ['contact_id' => 505, 'inbox_id' => 91, 'message' => ['content' => 'Escreva para Bia', 'private' => true]],
        ], $this->bodies);
    }

    /**
     * @param list<Entity> $people
     * @param array<string, int> $chatwootContacts CRM contact id => Chatwoot contact id (others have no number)
     */
    private function action(array $people, array $chatwootContacts): OpenConversationWithNote
    {
        $inbox = $this->entity('ChatwootInbox', 'inbox1', ['chatwootAccountId' => 'cwacc']);
        $cwAccount = $this->entity('ChatwootAccount', 'cwacc', ['platformId' => 'plat1']);
        $runAs = $this->entity('ChatwootUser', 'cwu', ['chatwootUserId' => 100, 'userAccessToken' => 'own-token']);

        $em = $this->createStub(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn ($type, $id) => $type === 'ChatwootAccount' && $id === 'cwacc' ? $cwAccount : null
        );
        $em->method('getRDBRepository')->willReturnCallback(function (string $type) use ($chatwootContacts, $runAs) {
            $where = [];
            $builder = $this->createStub(RDBSelectBuilder::class);
            $builder->method('where')->willReturnCallback(function ($clause) use (&$where, $builder) {
                $where = $clause;

                return $builder;
            });
            $builder->method('order')->willReturnSelf();
            $builder->method('findOne')->willReturnCallback(function () use (&$where, $type, $chatwootContacts, $runAs) {
                if ($type === 'ChatwootUser') {
                    return $runAs;
                }

                $id = $chatwootContacts[$where['contactId'] ?? ''] ?? null;

                return $type === 'ChatwootContact' && $id
                    ? $this->entity('ChatwootContact', 'l' . $id, ['chatwootContactId' => $id])
                    : null;
            });
            $repo = $this->createStub(RDBRepository::class);
            $repo->method('where')->willReturnCallback(fn ($clause) => $builder->where($clause));

            return $repo;
        });

        $outbound = $this->createStub(JourneyWhatsAppOutbound::class);
        $outbound->method('isOptedOut')->willReturnCallback(fn (Entity $e) => (bool) $e->get('whatsAppOptedOut'));
        $outbound->method('resolveConnection')->willReturn([
            'platformUrl' => 'https://cw.test',
            'accountApiKey' => 'integration-key',
            'externalAccountId' => 13,
            'externalInboxId' => 91,
            'channelType' => 'whatsappCloudApi',
            'inbox' => $inbox,
        ]);
        $outbound->method('resolvePhones')->willReturn([]);
        $outbound->method('renderText')->willReturnCallback(
            function (string $text, Entity $target, ?Entity $person = null): array {
                $first = $person ? (string) $person->get('firstName') : '';

                return [
                    'text' => str_replace('{{contact.firstName}}', $first, $text),
                    'empty' => $first === '' ? ['{{contact.firstName}}'] : [],
                ];
            }
        );

        $api = $this->createStub(ChatwootApiClient::class);
        $api->method('accountRequest')->willReturnCallback(function (...$args) {
            $this->bodies[] = $args[4];

            return ['code' => 200, 'body' => ['id' => 77]];
        });

        $journeyPeople = $this->createStub(JourneyTargetPeople::class);
        $journeyPeople->method('fansOut')->willReturn(true);
        $journeyPeople->method('forTarget')->willReturn($people);

        return new OpenConversationWithNote(
            $outbound,
            $this->createStub(TenantGuard::class),
            $em,
            $api,
            $this->createStub(Log::class),
            $journeyPeople,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function context(Entity $target, array $params): ActionContext
    {
        $actor = $this->createStub(User::class);
        $actor->method('getId')->willReturn('u1');
        $actor->method('getUserName')->willReturn('antonio');

        return new ActionContext(
            $target,
            $this->entity('JourneyRecord', 'r1', []),
            $this->entity('JourneyStage', 's1', []),
            $this->entity('Journey', 'j1', []),
            'OnEnter',
            $params,
            't1',
            $actor,
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
        $entity->method('hasAttribute')->willReturnCallback(fn (string $attr) => array_key_exists($attr, $values));

        return $entity;
    }
}
