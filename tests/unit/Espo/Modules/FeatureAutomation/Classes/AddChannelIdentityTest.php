<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Classes;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\FeatureAutomation\Classes\AutomationActions\AddChannelIdentity;
use Espo\Modules\FeatureAutomation\Services\ContactChannelWriter;
use Espo\Modules\FeatureAutomation\Services\PayloadBag;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class AddChannelIdentityTest extends TestCase
{
    private array $payload = [];
    private array $calls = [];

    protected function setUp(): void
    {
        $this->payload = ['_trigger' => ['enrichment' => ['attemptId' => 'att1', 'product' => 'phone', 'index' => 0]]];
        $this->calls = [];
    }

    public function testFoundPhoneIsAddedAsTheRunAsUser(): void
    {
        $this->action('added')->run($this->context(['channel' => 'phone']));

        $this->assertSame([['tenant1', 'contact1', 'phone', ['kind' => 'purchased', 'receiptId' => 'att1', 'index' => 0]]], $this->calls);
        $this->assertSame('added', $this->payload['channelIdentity']['phone']);
    }

    public function testEmailChannelOnAFoundPhoneIsNotApplicable(): void
    {
        $this->action('added')->run($this->context(['channel' => 'email']));

        $this->assertSame([], $this->calls);
        $this->assertSame('not_applicable', $this->payload['channelIdentity']['email']);
    }

    public function testAlreadyRecordedOutcomeIsNotWrittenAgain(): void
    {
        $this->payload['channelIdentity']['phone'] = 'added';

        $this->action('added')->run($this->context(['channel' => 'phone']));

        $this->assertSame([], $this->calls);
    }

    public function testErasedOrAlteredReceiptIsUnavailable(): void
    {
        $this->action(new NotFound('gone'))->run($this->context(['channel' => 'phone', 'as' => 'found']));
        $this->assertSame('unavailable', $this->payload['channelIdentity']['found']);

        $this->action(new Conflict('erased'))->run($this->context(['channel' => 'whatsapp']));
        $this->assertSame('unavailable', $this->payload['channelIdentity']['whatsapp']);
    }

    public function testUnknownChannelIsRejected(): void
    {
        $this->expectException(Error::class);

        $this->action('added')->run($this->context(['channel' => 'sms']));
    }

    private function action(string|\Throwable $result): AddChannelIdentity
    {
        $writer = $this->createMock(ContactChannelWriter::class);
        $writer->method('add')->willReturnCallback(function (...$args) use ($result) {
            $this->calls[] = $args;
            if ($result instanceof \Throwable) {
                throw $result;
            }

            return $result;
        });

        $actors = $this->createMock(ActorScope::class);
        $actors->method('run')->willReturnCallback(
            fn (string $actorId, callable $work) => $work(fn (string $class) => $writer)
        );

        return new AddChannelIdentity($this->createMock(EntityManager::class), new PayloadBag(), $actors);
    }

    private function context(array $params): ActionContext
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
        $target->method('getEntityType')->willReturn('Contact');
        $target->method('getId')->willReturn('contact1');

        $actor = $this->createMock(User::class);
        $actor->method('getId')->willReturn('runAs1');

        $automation = $this->createMock(Entity::class);

        return new ActionContext($target, $item, $automation, $automation, 'automation', $params, 'tenant1', $actor);
    }
}
