<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Hooks\Contact;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Hooks\Contact\SyncChatwootContactMerge;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class SyncChatwootContactMergeTest extends TestCase
{
    private function makeEntity(string $type, string $id, array &$attrs): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('getEntityType')->willReturn($type);
        $entity->method('isNew')->willReturn(false);
        $entity->method('get')->willReturnCallback(static function (string $key) use (&$attrs) {
            return $attrs[$key] ?? null;
        });
        $entity->method('set')->willReturnCallback(static function ($key, $value = null) use (&$attrs, $entity) {
            if (is_array($key)) {
                foreach ($key as $k => $v) {
                    $attrs[$k] = $v;
                }
            } else {
                $attrs[$key] = $value;
            }
            return $entity;
        });
        return $entity;
    }

    public function testCanonicalPhoneLineMatchesExactAndBrazilNinthDigit(): void
    {
        self::assertSame('559681100084', SyncChatwootContactMerge::canonicalPhoneLine('+5596981100084'));
        self::assertSame('559681100084', SyncChatwootContactMerge::canonicalPhoneLine('+559681100084'));
        self::assertNotSame(
            SyncChatwootContactMerge::canonicalPhoneLine('+5596981100084'),
            SyncChatwootContactMerge::canonicalPhoneLine('+5596991068427'),
        );
        self::assertNull(SyncChatwootContactMerge::canonicalPhoneLine(null));
        self::assertNull(SyncChatwootContactMerge::canonicalPhoneLine(''));
        self::assertNull(SyncChatwootContactMerge::canonicalPhoneLine('12345'));
    }

    public function testDoesNotMergeDifferentPhoneNumbersOrPhonelessContactsOnSameAccount(): void
    {
        $contactAttrs = ['firstName' => 'Alex'];
        $contact = $this->makeEntity('Contact', 'contact-alex', $contactAttrs);

        $c1Attrs = ['chatwootAccountId' => 'acc-1', 'chatwootContactId' => 54073, 'phoneNumber' => '+5596981360031'];
        $c2Attrs = ['chatwootAccountId' => 'acc-1', 'chatwootContactId' => 54904, 'phoneNumber' => '+5596991068427'];
        $c3Attrs = ['chatwootAccountId' => 'acc-1', 'chatwootContactId' => 54907, 'phoneNumber' => '+5596981100084'];
        $c4Attrs = ['chatwootAccountId' => 'acc-1', 'chatwootContactId' => 54999, 'phoneNumber' => null];

        $bridges = [
            $this->makeEntity('ChatwootContact', 'cc-1', $c1Attrs),
            $this->makeEntity('ChatwootContact', 'cc-2', $c2Attrs),
            $this->makeEntity('ChatwootContact', 'cc-3', $c3Attrs),
            $this->makeEntity('ChatwootContact', 'cc-4', $c4Attrs),
        ];

        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('find')->willReturn(new EntityCollection($bridges));

        $repo = $this->createMock(RDBRepository::class);
        $repo->method('where')->willReturn($builder);

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->with('ChatwootContact')->willReturn($repo);

        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::never())->method('mergeContacts');

        $hook = new SyncChatwootContactMerge($em, $api, $this->createMock(Log::class));
        $hook->afterSave($contact, []);
    }

    public function testMergesSameLineDuplicatesAndRepointsConversationsBeforeRemovingDuplicateContactInbox(): void
    {
        $contactAttrs = ['firstName' => 'Alex'];
        $contact = $this->makeEntity('Contact', 'contact-alex', $contactAttrs);

        $baseAttrs = ['chatwootAccountId' => 'acc-1', 'chatwootContactId' => 100, 'phoneNumber' => '+5596981100084'];
        $dupAttrs = ['chatwootAccountId' => 'acc-1', 'chatwootContactId' => 200, 'phoneNumber' => '+559681100084'];
        $otherAttrs = ['chatwootAccountId' => 'acc-1', 'chatwootContactId' => 300, 'phoneNumber' => '+5596991068427'];

        $base = $this->makeEntity('ChatwootContact', 'cc-base', $baseAttrs);
        $dup = $this->makeEntity('ChatwootContact', 'cc-dup', $dupAttrs);
        $other = $this->makeEntity('ChatwootContact', 'cc-other', $otherAttrs);

        $accountAttrs = ['platformId' => 'plat-1', 'apiKey' => 'key', 'chatwootAccountId' => 6];
        $account = $this->makeEntity('ChatwootAccount', 'acc-1', $accountAttrs);

        $platformAttrs = ['backendUrl' => 'https://chat.example.com'];
        $platform = $this->makeEntity('ChatwootPlatform', 'plat-1', $platformAttrs);

        $baseCiAttrs = ['chatwootContactId' => 'cc-base', 'chatwootInboxId' => 70];
        $dupCiAttrs = ['chatwootContactId' => 'cc-dup', 'chatwootInboxId' => 70];
        $baseCi = $this->makeEntity('ChatwootContactInbox', 'ci-base', $baseCiAttrs);
        $dupCi = $this->makeEntity('ChatwootContactInbox', 'ci-dup', $dupCiAttrs);

        $convAttrs = ['chatwootContactId' => 'cc-dup', 'contactInboxId' => 'ci-dup'];
        $conv = $this->makeEntity('ChatwootConversation', 'conv-1', $convAttrs);

        $events = [];

        $makeRepo = function (callable $resolver): RDBRepository {
            $repo = $this->createMock(RDBRepository::class);
            $repo->method('where')->willReturnCallback(function (array $where) use ($resolver) {
                $builder = $this->createMock(RDBSelectBuilder::class);
                $items = $resolver($where);
                $builder->method('find')->willReturn(new EntityCollection($items));
                $builder->method('findOne')->willReturn($items[0] ?? null);
                return $builder;
            });
            return $repo;
        };

        $cwtContactRepo = $makeRepo(static fn () => [$base, $dup, $other]);
        $cwtConvRepo = $makeRepo(static function (array $where) use ($conv, &$convAttrs) {
            if (($where['chatwootContactId'] ?? null) === $convAttrs['chatwootContactId']) {
                return [$conv];
            }
            if (($where['contactInboxId'] ?? null) === $convAttrs['contactInboxId']) {
                return [$conv];
            }
            return [];
        });
        $cwtCiRepo = $makeRepo(static function (array $where) use ($baseCi, $dupCi) {
            if (($where['chatwootContactId'] ?? null) === 'cc-dup') {
                return [$dupCi];
            }
            if (($where['chatwootContactId'] ?? null) === 'cc-base' && ($where['chatwootInboxId'] ?? null) === 70) {
                return [$baseCi];
            }
            return [];
        });

        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturnMap([
            ['ChatwootContact', $cwtContactRepo],
            ['ChatwootConversation', $cwtConvRepo],
            ['ChatwootContactInbox', $cwtCiRepo],
        ]);
        $em->method('getEntityById')->willReturnMap([
            ['ChatwootAccount', 'acc-1', $account],
            ['ChatwootPlatform', 'plat-1', $platform],
        ]);
        $em->method('saveEntity')->willReturnCallback(static function (Entity $e) use (&$events) {
            $events[] = 'save:' . $e->getEntityType() . ':' . $e->getId() . ':' . ($e->get('contactInboxId') ?? '');
            return $e;
        });
        $em->method('removeEntity')->willReturnCallback(static function (Entity $e) use (&$events, &$convAttrs) {
            // Assert conversation was ALREADY repointed before duplicate contactInbox removal!
            self::assertSame('ci-base', $convAttrs['contactInboxId']);
            $events[] = 'remove:' . $e->getEntityType() . ':' . $e->getId();
            return true;
        });

        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects(self::once())
            ->method('mergeContacts')
            ->with('https://chat.example.com', 'key', 6, 100, 200);

        $hook = new SyncChatwootContactMerge($em, $api, $this->createMock(Log::class));
        $hook->afterSave($contact, []);

        self::assertSame('merged', $dupAttrs['syncStatus']);
        self::assertSame(100, $dupAttrs['mergedIntoChatwootContactId']);
        self::assertSame('cc-base', $convAttrs['chatwootContactId']);
        self::assertSame('ci-base', $convAttrs['contactInboxId']);
        self::assertArrayNotHasKey('syncStatus', $otherAttrs);
        self::assertContains('remove:ChatwootContactInbox:ci-dup', $events);
    }
}
