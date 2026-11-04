<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\WahaApiClient;
use Espo\Modules\Chatwoot\Services\WahaInboxAdoption;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryBuilder;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Covers the phone-number comparison that guards against adopting a second
 * live channel for a number that already has one (which would let a campaign
 * message the same contact twice).
 */
class WahaInboxAdoptionTest extends TestCase
{
    public function testWahaMsisdnMatchesStoredE164(): void
    {
        // WAHA reports `5511933253711@c.us`; we store `+5511933253711`.
        $this->assertTrue(
            WahaInboxAdoption::isSamePhoneNumber('+5511933253711', '5511933253711')
        );
    }

    public function testFormattingIsIgnored(): void
    {
        $this->assertTrue(
            WahaInboxAdoption::isSamePhoneNumber('+55 11 93325-3711', '+5511933253711')
        );
        $this->assertTrue(
            WahaInboxAdoption::isSamePhoneNumber('(11) 93325-3711', '11933253711')
        );
    }

    public function testDifferentNumbersDoNotMatch(): void
    {
        $this->assertFalse(
            WahaInboxAdoption::isSamePhoneNumber('+5511933253711', '+5511963561157')
        );
    }

    /**
     * A suffix-based comparison would treat these as equal and wrongly block a
     * legitimate adoption, so the match must be exact on digits.
     */
    public function testDifferentCountryCodesDoNotMatch(): void
    {
        $this->assertFalse(
            WahaInboxAdoption::isSamePhoneNumber('+1555' . '11933253711', '+5511933253711')
        );
    }

    public function testMissingOrUnusableNumbersNeverMatch(): void
    {
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber(null, '+5511933253711'));
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber('+5511933253711', null));
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber(null, null));
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber('', '+5511933253711'));
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber('+++', '+5511933253711'));
    }

    /**
     * Two numbers that are both unusable must not be considered equal, or every
     * channel with a blank number would block adoption of every other.
     */
    public function testTwoBlankNumbersAreNotEqual(): void
    {
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber('', ''));
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber('-', '+'));
    }

    public function testNormalizeStripsNonDigits(): void
    {
        $this->assertSame('5511933253711', WahaInboxAdoption::normalizePhoneNumber('+55 (11) 93325-3711'));
    }

    public function testNormalizeReturnsNullWhenNothingToCompare(): void
    {
        $this->assertNull(WahaInboxAdoption::normalizePhoneNumber(null));
        $this->assertNull(WahaInboxAdoption::normalizePhoneNumber(''));
        $this->assertNull(WahaInboxAdoption::normalizePhoneNumber('n/a'));
    }

    /**
     * A channel adopted before its code was scanned becomes ACTIVE once its
     * session works. Its `chatwootInboxId` is the local ChatwootInbox id (a
     * hash), so the session is found by name, never by that id.
     */
    public function testRefreshActivatesAdoptedChannelWhoseSessionWorks(): void
    {
        $channel = $this->entity([
            'chatwootAccountId' => 'acc-1',
            'channelType' => 'whatsappQrcode',
            'chatwootInboxId' => '6aeac74f4a0d1fe22',
            'wahaPlatformId' => 'platform-1',
            'wahaSessionName' => 'qr_inbox_new',
            'status' => 'PENDING_QR',
            'phoneNumber' => null,
        ]);
        $saved = [];

        $adoption = $this->adoption([$channel], [
            ['name' => 'qr_inbox_new', 'status' => 'WORKING', 'me' => ['id' => '551150284172@c.us'],
                'apps' => [['accountId' => 20395, 'inboxId' => 27459]]],
        ], $saved);

        $this->assertSame(1, $adoption->refreshAdoptedStatuses($this->entity(['chatwootAccountId' => 20395], 'acc-1')));
        $this->assertSame('ACTIVE', $channel->get('status'));
        $this->assertSame('+551150284172', $channel->get('phoneNumber'));
        $this->assertCount(1, $saved);
    }

    /**
     * A session with the same name whose Chatwoot app targets another account
     * must not change this account's channel.
     */
    public function testRefreshIgnoresSessionOfAnotherAccount(): void
    {
        $channel = $this->entity([
            'chatwootAccountId' => 'acc-1',
            'channelType' => 'whatsappQrcode',
            'wahaPlatformId' => 'platform-1',
            'wahaSessionName' => 'qr_inbox_new',
            'status' => 'PENDING_QR',
        ]);
        $saved = [];

        $adoption = $this->adoption([$channel], [
            ['name' => 'qr_inbox_new', 'status' => 'WORKING', 'apps' => [['accountId' => 11, 'inboxId' => 5]]],
        ], $saved);

        $this->assertSame(0, $adoption->refreshAdoptedStatuses($this->entity(['chatwootAccountId' => 20395], 'acc-1')));
        $this->assertSame('PENDING_QR', $channel->get('status'));
        $this->assertSame([], $saved);
    }

    /**
     * A live QR channel in another workspace on the same handset does not stop
     * this workspace from adopting its inbox.
     */
    public function testAdoptsWhenTheNumberIsLiveInAnotherWorkspace(): void
    {
        $demo = $this->entity([
            'chatwootAccountId' => 'acc-demo',
            'wahaSessionName' => 'qr_inbox_demo',
            'channelType' => 'whatsappQrcode',
            'phoneNumber' => '+5511933253711',
            'status' => 'ACTIVE',
        ], 'channel-demo');
        $saved = [];
        $created = [];

        $adoption = $this->adoption([$demo], $this->sharedHandsetSessions(), $saved, $created);

        $this->assertNotNull($adoption->adoptIfWahaBacked($this->apiInbox(27460), 'acc-1'));
        $this->assertCount(1, $created);
        $this->assertSame('acc-1', $created[0]['chatwootAccountId']);
        $this->assertSame('qr_inbox_new', $created[0]['wahaSessionName']);
        $this->assertSame('ACTIVE', $created[0]['status']);
    }

    /**
     * The same handset already live in this workspace still blocks a second
     * channel: a campaign could message one contact through both.
     */
    public function testRefusesWhenTheNumberIsLiveInTheSameWorkspace(): void
    {
        $existing = $this->entity([
            'chatwootAccountId' => 'acc-1',
            'wahaSessionName' => 'qr_inbox_demo',
            'channelType' => 'whatsappQrcode',
            'status' => 'ACTIVE',
        ], 'channel-existing');
        $saved = [];
        $created = [];

        $adoption = $this->adoption([$existing], $this->sharedHandsetSessions(), $saved, $created);

        $this->assertNull($adoption->adoptIfWahaBacked($this->apiInbox(27460), 'acc-1'));
        $this->assertSame([], $created);
    }

    /**
     * Two WORKING sessions on one handset: the new one targets account 20395,
     * inbox 27460; the other targets account 11.
     *
     * @return list<array<string, mixed>>
     */
    private function sharedHandsetSessions(): array
    {
        return [
            ['name' => 'qr_inbox_new', 'status' => 'WORKING', 'me' => ['id' => '551150284172@c.us'],
                'apps' => [['accountId' => 20395, 'inboxId' => 27460]]],
            ['name' => 'qr_inbox_demo', 'status' => 'WORKING', 'me' => ['id' => '551150284172@c.us'],
                'apps' => [['accountId' => 11, 'inboxId' => 900]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function apiInbox(int $id): array
    {
        return ['id' => $id, 'name' => 'QR Code Teste', 'channel_type' => 'Channel::Api', 'inbox_identifier' => 'abc'];
    }

    /**
     * An adoption service over an in-memory channel table, one WAHA platform,
     * and the Chatwoot account acc-1 (Chatwoot id 20395).
     *
     * @param list<Entity> $channels
     * @param list<array<string, mixed>> $sessions
     * @param list<Entity> $saved
     * @param list<array<string, mixed>> $created
     */
    private function adoption(array $channels, array $sessions, array &$saved, array &$created = []): WahaInboxAdoption
    {
        $select = function (array $clause) use ($channels): RDBSelectBuilder {
            $rows = array_values(array_filter($channels, function (Entity $row) use ($clause): bool {
                foreach ($clause as $key => $value) {
                    $negate = str_ends_with($key, '!=');
                    $actual = $row->get(rtrim($key, '!='));
                    $match = is_array($value) ? in_array($actual, $value, true) : $actual === $value;

                    if ($match === $negate) {
                        return false;
                    }
                }

                return true;
            }));

            $builder = $this->createMock(RDBSelectBuilder::class);
            $builder->method('find')->willReturn(new EntityCollection($rows));
            $builder->method('findOne')->willReturn($rows[0] ?? null);

            return $builder;
        };

        $integrations = $this->createMock(RDBRepository::class);
        $integrations->method('where')->willReturnCallback(fn (array $clause) => $select($clause));
        // Session claimed by any channel, deleted included: none in these tests.
        $integrations->method('clone')->willReturn($select(['wahaSessionName' => '-']));

        $platforms = $this->createMock(RDBRepository::class);
        $platforms->method('find')->willReturn(new EntityCollection([
            $this->entity(['backendUrl' => 'http://waha', 'apiKey' => 'key'], 'platform-1'),
        ]));

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $selectBuilder = $this->createMock(SelectBuilder::class);
        $queryBuilder->method('select')->willReturn($selectBuilder);
        $selectBuilder->method('from')->willReturnSelf();
        $selectBuilder->method('where')->willReturnSelf();
        $selectBuilder->method('withDeleted')->willReturnSelf();
        $selectBuilder->method('build')->willReturn($this->createMock(Select::class));

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getQueryBuilder')->willReturn($queryBuilder);
        $entityManager->method('getRDBRepository')->willReturnCallback(
            fn (string $type) => $type === 'WahaPlatform' ? $platforms : $integrations
        );
        $entityManager->method('getEntityById')->willReturnCallback(
            fn (string $type, string $id) => $type === 'ChatwootAccount' && $id === 'acc-1'
                ? $this->entity(['chatwootAccountId' => 20395], 'acc-1')
                : null
        );
        $entityManager->method('saveEntity')->willReturnCallback(
            function (Entity $entity) use (&$saved): void {
                $saved[] = $entity;
            }
        );
        $entityManager->method('createEntity')->willReturnCallback(
            function (string $type, array $data) use (&$created): Entity {
                $created[] = $data;

                return $this->entity($data, 'channel-new');
            }
        );

        $client = $this->createMock(WahaApiClient::class);
        $client->method('listSessions')->willReturn($sessions);
        $client->method('listApps')->willReturnCallback(function (string $url, string $key, string $name) use ($sessions) {
            foreach ($sessions as $session) {
                if ($session['name'] === $name) {
                    return array_map(
                        fn (array $config) => ['app' => 'chatwoot', 'enabled' => true, 'config' => $config],
                        $session['apps']
                    );
                }
            }

            return [];
        });

        return new WahaInboxAdoption($entityManager, $client, $this->createMock(Log::class));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function entity(array $values, string $id = 'channel-1'): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(function (string $name) use (&$values) {
            return $values[$name] ?? null;
        });
        $entity->method('set')->willReturnCallback(function ($name, $value = null) use (&$values, $entity) {
            $values[$name] = $value;

            return $entity;
        });

        return $entity;
    }
}
