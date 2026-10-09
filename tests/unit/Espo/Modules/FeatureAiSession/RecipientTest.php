<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\FeatureAiSession;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureAiSession\Services\Access;
use Espo\Modules\FeatureAiSession\Services\Recipient;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Chatwoot/Support/EntityDouble.php';

class RecipientTest extends TestCase
{
    private function resolve(string $post): string
    {
        $agents = [
            'first' => new EntityDouble(['id' => 'first', 'isAI' => true]),
            'second' => new EntityDouble(['id' => 'second', 'isAI' => true]),
            'human' => new EntityDouble(['id' => 'human', 'isAI' => false]),
            'foreign' => new EntityDouble(['id' => 'foreign', 'isAI' => true]),
        ];
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $agents[$id] ?? null);
        $access = $this->createMock(Access::class);
        $access->method('agent')->willReturnCallback(function ($user, $session, $id) use ($agents) {
            if ($id === 'foreign') throw new Forbidden();
            return $agents[$id];
        });
        $session = new EntityDouble(['aiAgentMembershipId' => 'first']);
        $recipient = new Recipient($em, $access);
        $user = $this->createMock(User::class);
        $resolved = $recipient->resolve($user, $session, $post)->getId();
        self::assertSame('first', $session->get('aiAgentMembershipId'));
        self::assertSame('first', $recipient->resolve($user, $session, 'Next message')->getId());
        return $resolved;
    }

    public function testPlainMessageKeepsSelection(): void
    {
        self::assertSame('first', $this->resolve('Hello, help me plan my week.'));
    }

    public function testLastMentionWinsIncludingRepeatedEarlierAgent(): void
    {
        self::assertSame('second', $this->resolve('[A](#crm-reference/v1/record/ChatwootAccountUserMembership/first) [B](#crm-reference/v1/record/ChatwootAccountUserMembership/second)'));
        self::assertSame('first', $this->resolve('[A](#crm-reference/v1/record/ChatwootAccountUserMembership/first) [B](#crm-reference/v1/record/ChatwootAccountUserMembership/second) [A](#crm-reference/v1/record/ChatwootAccountUserMembership/first)'));
    }

    public function testQuotesCodeAndOtherRecordMentionsDoNotSwitch(): void
    {
        foreach ([
            '> [B](#crm-reference/v1/record/ChatwootAccountUserMembership/second)',
            '`[B](#crm-reference/v1/record/ChatwootAccountUserMembership/second)`',
            "```\n[B](#crm-reference/v1/record/ChatwootAccountUserMembership/second)\n```",
            '[Person](#crm-reference/v1/record/User/second)',
            '[Company](#crm-reference/v1/record/Account/second)',
            '[Human](#crm-reference/v1/record/ChatwootAccountUserMembership/human)',
        ] as $post) self::assertSame('first', $this->resolve($post), $post);
    }

    public function testForeignAgentFailsInsteadOfSilentlySendingToCurrent(): void
    {
        $this->expectException(Forbidden::class);
        $this->resolve('[Foreign](#crm-reference/v1/record/ChatwootAccountUserMembership/foreign)');
    }

    public function testMentionOnlySelectsTheRecipientForThisMessage(): void
    {
        self::assertSame('second', $this->resolve('[B](#crm-reference/v1/record/ChatwootAccountUserMembership/second)'));
    }
}
