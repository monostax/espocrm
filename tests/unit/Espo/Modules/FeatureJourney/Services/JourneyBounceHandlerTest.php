<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\InjectableFactory;
use Espo\Core\Mail\Account\GroupAccount\BouncedRecognizer;
use Espo\Core\Mail\Message;
use Espo\Core\Utils\Log;
use Espo\Entities\Email;
use Espo\Modules\FeatureJourney\Services\JourneyBounceHandler;
use Espo\Modules\FeatureJourney\Services\JourneyEmailToken;
use Espo\Modules\FeatureJourney\Services\JourneySignalDispatcher;
use Espo\Modules\FeatureJourney\Services\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use PHPUnit\Framework\TestCase;

class JourneyBounceHandlerTest extends TestCase
{
    public function testProcessDispatchesEmailBouncedViaMessageId(): void
    {
        $mint = JourneyEmailToken::mint('am@monostax.com.br');
        $messageId = $mint['messageId'];
        $token = $mint['token'];

        $message = $this->createMock(Message::class);
        $message->method('getRawContent')->willReturn(
            "From: MAILER-DAEMON@example.com\n" .
            "Content-Type: multipart/report; report-type=delivery-status; boundary=b\n\n" .
            "--b\n" .
            "Content-Type: message/delivery-status\n\n" .
            "Final-Recipient: rfc822; bounce@example.com\n" .
            "Status: 5.1.1\n\n" .
            "--b\n" .
            "Content-Type: message/rfc822\n\n" .
            "Message-ID: {$messageId}\n" .
            "To: bounce@example.com\n\n" .
            "--b--\n"
        );

        $bouncedRecognizer = new BouncedRecognizer();

        $original = $this->createMock(Entity::class);
        $original->method('hasId')->willReturn(true);
        $original->method('getId')->willReturn('email00000000001');
        $original->method('get')->willReturnCallback(static function (string $attr) use ($messageId, $token) {
            return match ($attr) {
                'journeyRecordId' => 'jr000000000000001',
                'journeyId' => 'jny00000000000001',
                'journeyToken' => $token,
                'messageId' => $messageId,
                'parentType' => 'Contact',
                'parentId' => 'ctc00000000000001',
                'to' => 'bounce@example.com',
                default => null,
            };
        });

        $emailRepo = $this->createMock(RDBRepository::class);
        $emailRepo->method('where')->willReturnSelf();
        $emailRepo->method('order')->willReturnSelf();
        $emailRepo->method('findOne')->willReturn($original);

        $target = $this->createMock(Entity::class);
        $target->method('getEntityType')->willReturn('Contact');
        $target->method('getId')->willReturn('ctc00000000000001');

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->with(Email::ENTITY_TYPE)->willReturn($emailRepo);
        $entityManager->method('getEntityById')->willReturnCallback(
            static function (string $type, string $id) use ($target) {
                if ($type === 'Contact' && $id === 'ctc00000000000001') {
                    return $target;
                }

                return null;
            }
        );
        $entityManager->method('getRepository')->willReturn(
            new class {
                public function getByAddress(string $address): null
                {
                    return null;
                }
            }
        );

        $dispatcher = $this->createMock(JourneySignalDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                'tenant1',
                JourneyEmailToken::CODE_BOUNCED,
                $target,
                $this->callback(static function (array $payload) use ($messageId, $token): bool {
                    return ($payload['journeyRecordId'] ?? null) === 'jr000000000000001'
                        && ($payload['isHard'] ?? null) === true
                        && ($payload['toAddress'] ?? null) === 'bounce@example.com'
                        && ($payload['journeyToken'] ?? null) === $token
                        && ($payload['messageId'] ?? null) === $messageId;
                }),
                null,
            );

        $tenantResolver = $this->createMock(TenantResolver::class);
        $tenantResolver->method('resolveTenantIdForEntity')->willReturn('tenant1');

        $handler = new JourneyBounceHandler(
            $entityManager,
            $bouncedRecognizer,
            $dispatcher,
            $tenantResolver,
            $this->createMock(InjectableFactory::class),
            $this->createMock(Log::class),
        );

        $this->assertTrue($handler->process($message, true));
    }

    public function testProcessReturnsFalseWhenNoJourneyEmail(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getRawContent')->willReturn(
            "From: MAILER-DAEMON@example.com\n\nStatus: 5.1.1\n"
        );

        $emailRepo = $this->createMock(RDBRepository::class);
        $emailRepo->method('where')->willReturnSelf();
        $emailRepo->method('order')->willReturnSelf();
        $emailRepo->method('findOne')->willReturn(null);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->willReturn($emailRepo);

        $dispatcher = $this->createMock(JourneySignalDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $handler = new JourneyBounceHandler(
            $entityManager,
            new BouncedRecognizer(),
            $dispatcher,
            $this->createMock(TenantResolver::class),
            $this->createMock(InjectableFactory::class),
            $this->createMock(Log::class),
        );

        $this->assertFalse($handler->process($message, true));
    }
}
