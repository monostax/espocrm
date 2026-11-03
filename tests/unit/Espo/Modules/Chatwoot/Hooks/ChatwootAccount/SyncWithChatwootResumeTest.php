<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Hooks\ChatwootAccount;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Hooks\ChatwootAccount\SyncWithChatwoot;
use Espo\Modules\Chatwoot\Services\ChatwootAccountCreationJournal;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ConciergeAvatarService;
use Espo\Modules\Chatwoot\Services\ConciergeEmailDomainResolver;
use Espo\Modules\Chatwoot\Services\IntegrationUserNameResolver;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A failed ChatwootAccount save must not leave Chatwoot with an account the
 * CRM forgets: saving again with the same id reuses what was created.
 */
class SyncWithChatwootResumeTest extends TestCase
{
    private const ACCOUNT_ID = 'cwacct1';
    private const EMAIL = 'concierge.55@guest.acme.example.test';

    private ChatwootApiClient&MockObject $api;
    private ChatwootAccountCreationJournal&MockObject $journal;

    /** @var list<array{0: string, 1: mixed}> */
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->api = $this->createMock(ChatwootApiClient::class);
        $this->journal = $this->createMock(ChatwootAccountCreationJournal::class);
        $this->journal->method('recordAccount')->willReturnCallback(
            function (string $id, int $remoteId): void {
                $this->recorded[] = ['account', $remoteId];
            }
        );
        $this->journal->method('recordConcierge')->willReturnCallback(
            function (string $id, int $userId): void {
                $this->recorded[] = ['concierge', $userId];
            }
        );
        SyncWithChatwoot::$conciergeUserDataCache = [];
    }

    public function testRecordsTheRemoteAccountBeforeTheConciergeStepCanFail(): void
    {
        $this->journal->method('find')->willReturn(null);
        $this->api->method('createAccount')->willReturn(['id' => 55]);
        $this->api->method('createUser')->willThrowException(new Error('Chatwoot API error: HTTP 500'));

        try {
            $this->hook()->beforeSave($this->account(), []);
            $this->fail('The save must fail when the concierge cannot be created.');
        } catch (Error) {
        }

        $this->assertSame([['account', 55]], $this->recorded);
    }

    public function testRetryReusesTheRecordedAccountAndConcierge(): void
    {
        $this->journal->method('find')->willReturn($this->journalRecord(55, 77));
        $this->api->expects($this->never())->method('createAccount');
        $this->api->expects($this->never())->method('createUser');
        $this->api->expects($this->once())->method('getAccount')->with($this->anything(), $this->anything(), 55)
            ->willReturn(['id' => 55]);
        $this->api->method('getUser')->willReturn($this->conciergeUser(77, 55));
        $this->api->expects($this->once())->method('updateUser')
            ->with($this->anything(), $this->anything(), 77, $this->callback(fn (array $d) => isset($d['password'])));
        $this->api->expects($this->once())->method('attachUserToAccount')
            ->with($this->anything(), $this->anything(), 55, 77, 'administrator', true, false)
            ->willReturn(['id' => 9]);

        $account = $this->account();
        $this->hook()->beforeSave($account, []);

        $this->assertSame(55, $account->get('chatwootAccountId'));
        $this->assertSame('concierge-token', $account->get('apiKey'));
        $this->assertSame(77, SyncWithChatwoot::$conciergeUserDataCache[self::ACCOUNT_ID]['user_id']);
        $this->assertSame(9, SyncWithChatwoot::$conciergeUserDataCache[self::ACCOUNT_ID]['account_user_id']);
    }

    public function testRetryCreatesOnlyTheConciergeWhenTheAccountWasRecordedAlone(): void
    {
        $this->journal->method('find')->willReturn($this->journalRecord(55, null));
        $this->api->expects($this->never())->method('createAccount');
        $this->api->method('getAccount')->willReturn(['id' => 55]);
        $this->api->expects($this->once())->method('createUser')->willReturn(['id' => 77, 'access_token' => 't']);
        $this->api->method('attachUserToAccount')->willReturn(['id' => 9]);

        $account = $this->account();
        $this->hook()->beforeSave($account, []);

        $this->assertSame(55, $account->get('chatwootAccountId'));
        $this->assertSame([['concierge', 77]], $this->recorded);
    }

    public function testRetryFailsRatherThanCreatingASecondAccountWhenChatwootCannotConfirmTheFirst(): void
    {
        $this->journal->method('find')->willReturn($this->journalRecord(55, null));
        $this->api->method('getAccount')->willThrowException(new Error('Failed to get account from Chatwoot: HTTP 502'));
        $this->api->expects($this->never())->method('createAccount');

        $this->expectException(Error::class);
        $this->hook()->beforeSave($this->account(), []);
    }

    /**
     * The reply to an earlier concierge creation was lost: Chatwoot now answers
     * 409 with the existing id, which is reused only if it carries this
     * account's concierge marker.
     */
    public function testAdoptsAnExistingConciergeCarryingThisAccountsMarker(): void
    {
        $this->journal->method('find')->willReturn(null);
        $this->api->method('createAccount')->willReturn(['id' => 55]);
        $this->api->method('createUser')->willReturn(['id' => 77, 'created' => false, 'existing' => true]);
        $this->api->method('getUser')->willReturn($this->conciergeUser(77, 55));
        $this->api->method('attachUserToAccount')->willReturn(['id' => 9]);

        $account = $this->account();
        $this->hook()->beforeSave($account, []);

        $this->assertSame('concierge-token', $account->get('apiKey'));
        $this->assertSame([['account', 55], ['concierge', 77]], $this->recorded);
    }

    public function testRefusesAnExistingUserThatIsNotThisAccountsConcierge(): void
    {
        $this->journal->method('find')->willReturn(null);
        $this->api->method('createAccount')->willReturn(['id' => 55]);
        $this->api->method('createUser')->willReturn(['id' => 77, 'created' => false, 'existing' => true]);
        $this->api->method('getUser')->willReturn($this->conciergeUser(77, 54));
        $this->api->expects($this->never())->method('attachUserToAccount');
        $this->api->expects($this->never())->method('updateUser');

        $this->expectException(Error::class);
        $this->hook()->beforeSave($this->account(), []);
    }

    private function hook(): SyncWithChatwoot
    {
        $platform = $this->entity('ChatwootPlatform', [
            'id' => 'platform1',
            'backendUrl' => 'https://chatwoot.example.test',
            'accessToken' => 'platform-token',
        ]);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            fn (string $type) => $type === 'ChatwootPlatform' ? $platform : null
        );
        $emails = $this->createMock(ConciergeEmailDomainResolver::class);
        $emails->method('resolveEmail')->willReturn(self::EMAIL);
        $names = $this->createMock(IntegrationUserNameResolver::class);
        $names->method('resolve')->willReturn('Concierge');

        return new SyncWithChatwoot(
            $em,
            $this->api,
            $this->createMock(Log::class),
            $this->createMock(ConciergeAvatarService::class),
            $emails,
            $names,
            $this->journal,
        );
    }

    private function account(): Entity
    {
        $entity = new CoreEntity('ChatwootAccount', ['attributes' => [
            'id' => ['type' => 'id'],
            'name' => ['type' => 'varchar'],
            'platformId' => ['type' => 'varchar'],
            'chatwootAccountId' => ['type' => 'int'],
            'apiKey' => ['type' => 'varchar'],
            'teamsIds' => ['type' => 'jsonArray', 'notStorable' => true],
        ]]);
        $entity->set(['id' => self::ACCOUNT_ID, 'name' => 'Acme', 'platformId' => 'platform1', 'teamsIds' => []]);

        return $entity;
    }

    /**
     * @return array{remoteAccountId: ?int, conciergeUserId: ?int, conciergeEmail: ?string}
     */
    private function journalRecord(int $remoteAccountId, ?int $conciergeUserId): array
    {
        return [
            'remoteAccountId' => $remoteAccountId,
            'conciergeUserId' => $conciergeUserId,
            'conciergeEmail' => $conciergeUserId ? self::EMAIL : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conciergeUser(int $id, int $accountId): array
    {
        return [
            'id' => $id,
            'name' => 'Concierge',
            'email' => self::EMAIL,
            'access_token' => 'concierge-token',
            'custom_attributes' => ['type' => 'concierge', 'created_by' => 'espocrm', 'account_id' => $accountId],
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function entity(string $type, array $values): Entity
    {
        $entity = new BaseEntity($type, ['attributes' => array_fill_keys(array_keys($values), ['type' => 'varchar'])]);
        $entity->set($values);

        return $entity;
    }
}
