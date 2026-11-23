<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Classes;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\FeatureAutomation\Classes\AutomationActions\ActionWaiting;
use Espo\Modules\FeatureAutomation\Classes\AutomationActions\VerifyContactChannel;
use Espo\Modules\FeatureAutomation\Services\ContactVerification;
use Espo\Modules\FeatureAutomation\Services\PayloadBag;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class VerifyContactChannelTest extends TestCase
{
    private array $payload = [];
    private int $saves = 0;
    /** @var list<array{string, string, string, array, string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->payload = ['_trigger' => ['enrichment' => ['attemptId' => 'att1', 'product' => 'phone', 'index' => 1]]];
        $this->saves = 0;
        $this->calls = [];
    }

    public function testCompletedCheckIsRecordedForLaterConditions(): void
    {
        $this->action(['operation' => ['id' => 'op1', 'state' => 'completed', 'outcome' => 'registered', 'settledCredits' => '2.0000']])
            ->run($this->context(['check' => 'whatsapp_registration']));

        $this->assertSame('registered', $this->payload['verification']['whatsapp_registration']);
        $this->assertSame(['operationId' => 'op1', 'settledCredits' => '2.0000'], $this->payload['verificationInfo']['whatsapp_registration']);
        $this->assertSame(1, $this->saves);

        [$tenant, $contact, $kind, $source, $requestKey] = $this->calls[0];
        $this->assertSame(['tenant1', 'contact1', 'whatsapp_registration'], [$tenant, $contact, $kind]);
        $this->assertSame(['kind' => 'purchased', 'receiptId' => 'att1', 'index' => 1], $source);
        $this->assertMatchesRegularExpression('/\Aautomation:[a-f0-9]{40}\z/', $requestKey);
    }

    public function testRequestKeyIsStablePerItemAndResultKey(): void
    {
        $result = ['operation' => ['id' => 'op1', 'state' => 'pending']];

        foreach ([1, 2] as $_) {
            try {
                $this->action($result)->run($this->context(['check' => 'whatsapp_registration']));
                $this->fail('Pending checks park the item.');
            } catch (ActionWaiting) {
            }
        }

        $this->assertSame($this->calls[0][4], $this->calls[1][4]);
    }

    public function testPendingCheckParksTheItemAndRemembersWhenItStarted(): void
    {
        try {
            $this->action(['operation' => ['id' => 'op1', 'state' => 'pending']])
                ->run($this->context(['check' => 'phone_identity']));
            $this->fail('Expected the item to park.');
        } catch (ActionWaiting $wait) {
            $this->assertSame('verification_pending', $wait->getMessage());
            $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), $wait->wakeAt);
        }

        $this->assertArrayNotHasKey('verification', $this->payload);
        $this->assertSame('op1', $this->payload['_verify']['phone_identity']['operationId']);
        $this->assertNotEmpty($this->payload['_verify']['phone_identity']['since']);
    }

    public function testCheckThatNeverAnswersFailsAfterFifteenMinutes(): void
    {
        $this->payload['_verify']['phone_identity'] = ['since' => gmdate('Y-m-d H:i:s', time() - 901), 'operationId' => 'op1'];

        $this->action(['operation' => ['id' => 'op1', 'state' => 'pending']])
            ->run($this->context(['check' => 'phone_identity']));

        $this->assertSame('failed', $this->payload['verification']['phone_identity']);
        $this->assertSame('timeout', $this->payload['verificationInfo']['phone_identity']['reason']);
        $this->assertArrayNotHasKey('_verify', $this->payload);
    }

    public function testCheckOfAnotherKindOfValueIsNotApplicableAndCostsNothing(): void
    {
        $this->action(['operation' => ['id' => 'never']])->run($this->context(['check' => 'email_deliverability']));

        $this->assertSame('not_applicable', $this->payload['verification']['email_deliverability']);
        $this->assertSame([], $this->calls);
    }

    public function testDecidedCheckIsNotStartedAgainOnResume(): void
    {
        $this->payload['verification']['whatsapp_registration'] = 'registered';

        $this->action(['operation' => ['id' => 'never']])->run($this->context(['check' => 'whatsapp_registration']));

        $this->assertSame([], $this->calls);
        $this->assertSame(0, $this->saves);
    }

    public function testRateLimitParksUntilTheLimitFreesUp(): void
    {
        $limit = Conflict::createWithBody('busy', '{"reason":"rate_limited","retryAfterSeconds":42}');

        try {
            $this->action($limit)->run($this->context(['check' => 'whatsapp_registration']));
            $this->fail('Expected the item to park.');
        } catch (ActionWaiting $wait) {
            $this->assertSame('rate_limited', $wait->getMessage());
            $this->assertEqualsWithDelta(time() + 42, strtotime($wait->wakeAt . ' UTC'), 2);
        }

        $this->assertArrayNotHasKey('verification', $this->payload);
    }

    public function testRunningOutOfCreditsFailsTheItem(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Not enough credits');

        $this->action(Conflict::createWithBody('no', '{"reason":"insufficient_credits"}'))
            ->run($this->context(['check' => 'phone_identity']));
    }

    public function testRefusedValueIsUnavailableWithItsReason(): void
    {
        $this->action(Conflict::createWithBody('no', '{"reason":"ownership_rejected"}'))
            ->run($this->context(['check' => 'phone_identity']));

        $this->assertSame('unavailable', $this->payload['verification']['phone_identity']);
        $this->assertSame('ownership_rejected', $this->payload['verificationInfo']['phone_identity']['reason']);
    }

    public function testErasedReceiptIsUnavailable(): void
    {
        $this->action(new NotFound('gone'))->run($this->context(['check' => 'whatsapp_registration']));

        $this->assertSame('unavailable', $this->payload['verification']['whatsapp_registration']);
        $this->assertSame('source_unavailable', $this->payload['verificationInfo']['whatsapp_registration']['reason']);
    }

    public function testProviderFailureIsRecordedAsFailed(): void
    {
        $this->action(['operation' => ['id' => 'op1', 'state' => 'failed', 'outcome' => 'failed', 'settledCredits' => '0.0000']])
            ->run($this->context(['check' => 'whatsapp_registration', 'as' => 'wa']));

        $this->assertSame('failed', $this->payload['verification']['wa']);
    }

    public function testContactPrimarySourceChecksTheSavedValue(): void
    {
        $this->payload = [];

        $this->action(['operation' => ['id' => 'op1', 'state' => 'completed', 'outcome' => 'deliverable']])
            ->run($this->context(['check' => 'email_deliverability', 'source' => 'contactPrimary']));

        $this->assertSame(['kind' => 'contact_email'], $this->calls[0][3]);
        $this->assertSame('deliverable', $this->payload['verification']['email_deliverability']);
    }

    public function testOnlyContactsCanBeVerified(): void
    {
        $this->expectException(Error::class);

        $this->action(['operation' => []])->run($this->context(['check' => 'whatsapp_registration'], 'Account'));
    }

    public function testStopWhenSkipsWithoutSpendingCreditsWhenContactAlreadySatisfiesGoal(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(1);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $this->action(['operation' => ['id' => 'never']], $pdo)
            ->run($this->context(['check' => 'whatsapp_registration', 'stopWhen' => 'verified_phone']));

        $this->assertSame('skipped_already_valid', $this->payload['verification']['whatsapp_registration']);
        $this->assertSame('contact_already_verified_phone', $this->payload['verificationInfo']['whatsapp_registration']['reason']);
        $this->assertSame([], $this->calls);
    }

    public function testStopWhenParksItemWhenAnotherVerificationIsInFlightForContact(): void
    {
        $goalStmt = $this->createMock(\PDOStatement::class);
        $goalStmt->method('fetchColumn')->willReturn(false);
        $inFlightStmt = $this->createMock(\PDOStatement::class);
        $inFlightStmt->method('fetchColumn')->willReturn(1);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($goalStmt, $inFlightStmt);

        try {
            $this->action(['operation' => ['id' => 'never']], $pdo)
                ->run($this->context(['check' => 'whatsapp_registration', 'stopWhen' => 'verified_phone']));
            $this->fail('Expected item to wait while another verification is in flight.');
        } catch (ActionWaiting $wait) {
            $this->assertSame('contact_verification_in_flight', $wait->getMessage());
        }

        $this->assertSame([], $this->calls);
    }

    private function action(array|\Throwable $result, ?\PDO $pdo = null): VerifyContactChannel
    {
        $entityManager = $this->createMock(EntityManager::class);
        if ($pdo !== null) {
            $entityManager->method('getPDO')->willReturn($pdo);
        }
        $entityManager->method('saveEntity')->willReturnCallback(function () {
            $this->saves++;
        });

        $verification = $this->createMock(ContactVerification::class);
        $verification->method('startOrRead')->willReturnCallback(
            function (string $tenant, string $contact, string $kind, array $source, string $key) use ($result): array {
                $this->calls[] = [$tenant, $contact, $kind, $source, $key];
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            }
        );

        $actors = $this->createMock(ActorScope::class);
        $actors->method('run')->willReturnCallback(function (string $actorId, callable $work) use ($verification) {
            $this->assertSame('runAs1', $actorId);

            return $work(function (string $class) use ($verification) {
                $this->assertSame(ContactVerification::class, $class);

                return $verification;
            });
        });

        return new VerifyContactChannel($entityManager, new PayloadBag(), $actors);
    }

    private function context(array $params, string $targetType = 'Contact'): ActionContext
    {
        $item = $this->createMock(Entity::class);
        $item->method('getId')->willReturn('item1');
        $item->method('hasId')->willReturn(true);
        $item->method('get')->willReturnCallback(fn (string $name) => $name === 'payload' ? $this->payload : null);
        $item->method('set')->willReturnCallback(function ($name, $value = null) use (&$item) {
            if ($name === 'payload') {
                $this->payload = $value;
            }

            return $item;
        });

        $target = $this->createMock(Entity::class);
        $target->method('getEntityType')->willReturn($targetType);
        $target->method('getId')->willReturn('contact1');

        $actor = $this->createMock(User::class);
        $actor->method('getId')->willReturn('runAs1');

        $automation = $this->createMock(Entity::class);

        return new ActionContext($target, $item, $automation, $automation, 'automation', $params, 'tenant1', $actor);
    }
}
