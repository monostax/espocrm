<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Tools;

use Espo\Modules\Chatwoot\Tools\DuplicateContactMerger;
use PDO;
use PHPUnit\Framework\TestCase;

final class DuplicateContactMergerTest extends TestCase
{
    private PDO $pdo;
    private DuplicateContactMerger $merger;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ([
            'CREATE TABLE contact (id TEXT PRIMARY KEY, deleted BOOLEAN DEFAULT FALSE, tenant_id TEXT, modified_at TEXT)',
            'CREATE TABLE chatwoot_contact (id TEXT PRIMARY KEY, deleted BOOLEAN DEFAULT FALSE, contact_id TEXT, chatwoot_account_id TEXT, phone_number TEXT, modified_at TEXT)',
            'CREATE TABLE chatwoot_contact_inbox (id TEXT PRIMARY KEY, contact_id TEXT)',
            'CREATE TABLE contact_channel_identity (id TEXT PRIMARY KEY, deleted BOOLEAN DEFAULT FALSE, contact_id TEXT, tenant_id TEXT, channel_type TEXT, source_id TEXT, modified_at TEXT)',
            'CREATE TABLE entity_phone_number (id TEXT PRIMARY KEY, entity_id TEXT, entity_type TEXT, phone_number_id TEXT, "primary" BOOLEAN, deleted BOOLEAN DEFAULT FALSE)',
            'CREATE TABLE entity_email_address (id TEXT PRIMARY KEY, entity_id TEXT, entity_type TEXT, email_address_id TEXT, "primary" BOOLEAN, deleted BOOLEAN DEFAULT FALSE)',
            'CREATE TABLE account_contact (id TEXT PRIMARY KEY, account_id TEXT, contact_id TEXT, deleted BOOLEAN DEFAULT FALSE)',
            'CREATE TABLE chatwoot_conversation (id TEXT PRIMARY KEY, contact_id TEXT, deleted BOOLEAN DEFAULT FALSE)',
            'CREATE TABLE contact_opportunity (id TEXT PRIMARY KEY, contact_id TEXT, opportunity_id TEXT, deleted BOOLEAN DEFAULT FALSE)',
            'CREATE TABLE note (id TEXT PRIMARY KEY, parent_id TEXT, parent_type TEXT, deleted BOOLEAN DEFAULT FALSE)',
            'CREATE TABLE chatwoot_account (id TEXT PRIMARY KEY, tenant_id TEXT)',
        ] as $sql) $this->pdo->exec($sql);
        $references = static fn () => [
            ['table' => 'chatwoot_contact', 'column' => 'contact_id', 'parent' => false, 'deleted' => true],
            ['table' => 'chatwoot_contact_inbox', 'column' => 'contact_id', 'parent' => false, 'deleted' => false],
            ['table' => 'contact_channel_identity', 'column' => 'contact_id', 'parent' => false, 'deleted' => true],
            ['table' => 'account_contact', 'column' => 'contact_id', 'parent' => false, 'deleted' => true],
            ['table' => 'chatwoot_conversation', 'column' => 'contact_id', 'parent' => false, 'deleted' => true],
            ['table' => 'contact_opportunity', 'column' => 'contact_id', 'parent' => false, 'deleted' => true],
            ['table' => 'note', 'column' => 'parent_id', 'parent' => true, 'deleted' => true],
        ];
        $this->merger = new DuplicateContactMerger($this->pdo, $references);
        // Survivor (13-digit, with the 9) and loser (12-digit) for the same WhatsApp line.
        $this->insert('chatwoot_account', ['id' => 'acc', 'tenant_id' => 't1']);
        $this->insert('chatwoot_account', ['id' => 'acc-t2', 'tenant_id' => 't2']);
        $this->insert('contact', ['id' => 'survivor', 'tenant_id' => 't1']);
        $this->insert('contact', ['id' => 'loser', 'tenant_id' => 't1']);
        $this->insert('chatwoot_contact', ['id' => 'cc-s', 'contact_id' => 'survivor', 'chatwoot_account_id' => 'acc', 'phone_number' => '+5534991234567']);
        $this->insert('chatwoot_contact', ['id' => 'cc-l', 'contact_id' => 'loser', 'chatwoot_account_id' => 'acc', 'phone_number' => '+553491234567']);
        $this->insert('chatwoot_contact_inbox', ['id' => 'cci-l', 'contact_id' => 'loser']);
        $this->insert('contact_channel_identity', ['id' => 'id-shared-s', 'contact_id' => 'survivor', 'tenant_id' => 't1', 'channel_type' => 'whatsapp', 'source_id' => '+5534991234567']);
        $this->insert('contact_channel_identity', ['id' => 'id-shared-l', 'contact_id' => 'loser', 'tenant_id' => 't1', 'channel_type' => 'whatsapp', 'source_id' => '+5534991234567']);
        $this->insert('contact_channel_identity', ['id' => 'id-own-l', 'contact_id' => 'loser', 'tenant_id' => 't1', 'channel_type' => 'sms', 'source_id' => '+5534991234567']);
        $this->insert('entity_phone_number', ['id' => 'ph-l', 'entity_id' => 'loser', 'entity_type' => 'Contact', 'phone_number_id' => 'pn-12', 'primary' => 1]);
        $this->insert('entity_email_address', ['id' => 'em-s', 'entity_id' => 'survivor', 'entity_type' => 'Contact', 'email_address_id' => 'ea-1', 'primary' => 1]);
        $this->insert('entity_email_address', ['id' => 'em-l', 'entity_id' => 'loser', 'entity_type' => 'Contact', 'email_address_id' => 'ea-1', 'primary' => 1]);
        $this->insert('account_contact', ['id' => 'ac-l', 'account_id' => 'account-x', 'contact_id' => 'loser']);
    }

    private function insert(string $table, array $values): void
    {
        $columns = implode(', ', array_map(static fn ($c) => "\"$c\"", array_keys($values)));
        $this->pdo->prepare("INSERT INTO $table ($columns) VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ')')
            ->execute(array_values($values));
    }

    private function snapshot(): array
    {
        $all = [];
        foreach (['contact', 'chatwoot_contact', 'chatwoot_contact_inbox', 'contact_channel_identity', 'entity_phone_number', 'entity_email_address', 'account_contact'] as $t) {
            $all[$t] = $this->pdo->query("SELECT * FROM $t ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $all;
    }

    private function value(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }

    public function testSameLineMatchesOnlyTheBrazilianNinthDigitVariant(): void
    {
        self::assertTrue(DuplicateContactMerger::sameLine('+5534991234567', '+553491234567'));
        self::assertTrue(DuplicateContactMerger::sameLine('+55 (34) 9123-4567', '553491234567'));
        self::assertTrue(DuplicateContactMerger::sameLine('+5534991234567', '+5534991234567'));
        self::assertFalse(DuplicateContactMerger::sameLine('+5534991234567', '+553591234567'), 'different area code');
        self::assertFalse(DuplicateContactMerger::sameLine('+5534981234567', '+553491234567'), 'extra digit is not a 9');
        self::assertFalse(DuplicateContactMerger::sameLine('+4434991234567', '+443491234567'), 'not Brazil');
        self::assertFalse(DuplicateContactMerger::sameLine('', '+553491234567'));
    }

    public function testDryRunReportsTheMergeAndWritesNothing(): void
    {
        $before = $this->snapshot();
        $result = $this->merger->merge('survivor', 'loser', false);
        self::assertSame('would merge', $result['status']);
        self::assertSame(1, $result['counts']['contacts soft-deleted']);
        self::assertSame($before, $this->snapshot());
    }

    public function testApplyMovesHandledLinksAndSoftDeletesTheLoser(): void
    {
        $result = $this->merger->merge('survivor', 'loser', true);
        self::assertSame('merged', $result['status']);
        self::assertSame('survivor', $this->value("SELECT contact_id FROM chatwoot_contact WHERE id = 'cc-l'"));
        self::assertSame('survivor', $this->value("SELECT contact_id FROM chatwoot_contact_inbox WHERE id = 'cci-l'"));
        // Same identity on both: the loser's copy is soft-deleted; its own sms identity moves.
        self::assertSame(1, (int) $this->value("SELECT deleted FROM contact_channel_identity WHERE id = 'id-shared-l'"));
        self::assertSame('survivor', $this->value("SELECT contact_id FROM contact_channel_identity WHERE id = 'id-own-l'"));
        // The 12-digit phone moves (not primary); the shared email link is dropped.
        self::assertSame('survivor', $this->value("SELECT entity_id FROM entity_phone_number WHERE id = 'ph-l'"));
        self::assertSame(0, (int) $this->value("SELECT \"primary\" FROM entity_phone_number WHERE id = 'ph-l'"));
        self::assertSame(1, (int) $this->value("SELECT deleted FROM entity_email_address WHERE id = 'em-l'"));
        self::assertSame('survivor', $this->value("SELECT contact_id FROM account_contact WHERE id = 'ac-l'"));
        self::assertSame(1, (int) $this->value("SELECT deleted FROM contact WHERE id = 'loser'"));
        self::assertSame(0, (int) $this->value("SELECT deleted FROM contact WHERE id = 'survivor'"));
        self::assertSame('refused', $this->merger->merge('survivor', 'loser', true)['status'], 'idempotent');
    }

    /** @return iterable<string, array{callable(self): void, string}> */
    public static function refusals(): iterable
    {
        yield 'loser has a conversation' => [fn (self $t) => $t->insert('chatwoot_conversation', ['id' => 'cv', 'contact_id' => 'loser']), 'chatwoot_conversation.contact_id'];
        yield 'loser has a note' => [fn (self $t) => $t->insert('note', ['id' => 'n', 'parent_id' => 'loser', 'parent_type' => 'Contact']), 'note.parent_id'];
        yield 'loser on an opportunity' => [fn (self $t) => $t->insert('contact_opportunity', ['id' => 'co', 'contact_id' => 'loser', 'opportunity_id' => 'o']), 'contact_opportunity.contact_id'];
        yield 'different tenant' => [fn (self $t) => $t->pdo->exec("UPDATE contact SET tenant_id = 't2' WHERE id = 'loser'"), 'different or missing tenant'];
        yield 'different Chatwoot account' => [fn (self $t) => $t->pdo->exec("UPDATE chatwoot_contact SET chatwoot_account_id = 'other' WHERE id = 'cc-l'"), 'no shared Chatwoot account'];
        yield 'different phone line' => [fn (self $t) => $t->pdo->exec("UPDATE chatwoot_contact SET phone_number = '+553591234567' WHERE id = 'cc-l'"), 'no shared Chatwoot account'];
        yield 'survivor deleted' => [fn (self $t) => $t->pdo->exec("UPDATE contact SET deleted = TRUE WHERE id = 'survivor'"), 'contact missing or deleted'];
    }

    /** @dataProvider refusals */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testRefusedPairsAreLeftUntouched(callable $arrange, string $reason): void
    {
        $arrange($this);
        $before = $this->snapshot();
        $result = $this->merger->merge('survivor', 'loser', true);
        self::assertSame('refused', $result['status']);
        self::assertStringContainsString($reason, $result['reason']);
        self::assertSame($before, $this->snapshot());
    }

    /** The loser contact was purged: only its Chatwoot bridge still names it. */
    private function purgeLoser(): void
    {
        foreach (["DELETE FROM contact WHERE id = 'loser'", "DELETE FROM contact_channel_identity WHERE contact_id = 'loser'",
            "DELETE FROM entity_phone_number WHERE entity_id = 'loser'", "DELETE FROM entity_email_address WHERE entity_id = 'loser'",
            "DELETE FROM account_contact WHERE contact_id = 'loser'"] as $sql) $this->pdo->exec($sql);
    }

    public function testDanglingBridgeIsRepointedAndNothingIsDeleted(): void
    {
        $this->purgeLoser();
        $before = $this->snapshot();
        $dry = $this->merger->mergeOrRepair('survivor', 'loser', false);
        self::assertSame('would repair', $dry['status']);
        self::assertSame(['dangling bridges repointed' => 1, 'dangling inbox links repointed' => 1], $dry['counts']);
        self::assertSame($before, $this->snapshot());
        self::assertSame('repaired', $this->merger->mergeOrRepair('survivor', 'loser', true)['status']);
        self::assertSame('survivor', $this->value("SELECT contact_id FROM chatwoot_contact WHERE id = 'cc-l'"));
        self::assertSame('survivor', $this->value("SELECT contact_id FROM chatwoot_contact_inbox WHERE id = 'cci-l'"));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM contact WHERE deleted = FALSE'));
        self::assertSame('refused', $this->merger->mergeOrRepair('survivor', 'loser', true)['status'], 'idempotent');
    }

    /** @return iterable<string, array{callable(self): void, string}> */
    public static function danglingRefusals(): iterable
    {
        yield 'bridge account in another tenant' => [fn (self $t) => $t->pdo->exec("UPDATE chatwoot_contact SET chatwoot_account_id = 'acc-t2' WHERE id = 'cc-l'"), 'another tenant'];
        yield 'different phone line' => [fn (self $t) => $t->pdo->exec("UPDATE chatwoot_contact SET phone_number = '+553591234567' WHERE id = 'cc-l'"), 'same phone line'];
        yield 'dangling id still owns an identity' => [fn (self $t) => $t->insert('contact_channel_identity', ['id' => 'i9', 'contact_id' => 'loser', 'tenant_id' => 't1', 'channel_type' => 'email', 'source_id' => 'x']), 'contact_channel_identity'];
        yield 'dangling id has a conversation' => [fn (self $t) => $t->insert('chatwoot_conversation', ['id' => 'cv', 'contact_id' => 'loser']), 'chatwoot_conversation'];
        yield 'survivor deleted' => [fn (self $t) => $t->pdo->exec("UPDATE contact SET deleted = TRUE WHERE id = 'survivor'"), 'survivor missing'];
    }

    /** @dataProvider danglingRefusals */
    #[\PHPUnit\Framework\Attributes\DataProvider('danglingRefusals')]
    public function testDanglingRepairRefusalsLeaveDataUntouched(callable $arrange, string $reason): void
    {
        $this->purgeLoser();
        $arrange($this);
        $before = $this->snapshot();
        $result = $this->merger->mergeOrRepair('survivor', 'loser', true);
        self::assertSame('refused', $result['status']);
        self::assertStringContainsString($reason, $result['reason']);
        self::assertSame($before, $this->snapshot());
    }
}
