<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tests;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\CodeRejected;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\CodeWhatsApp;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\PhoneCodes;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\TwilioVerify;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PhoneCodesTest extends TestCase
{
    private const PHONE = '+5511912345678';
    private const SIGNUP = ['signupId' => 's1'];

    /** @var list<Entity> */
    private array $rows = [];
    private int $ids = 0;
    /** @var list<string> codes sent by WhatsApp, in order */
    private array $sent = [];

    private function row(array $values): Entity
    {
        $data = $values;
        $entity = $this->createMock(BaseEntity::class);
        $entity->method('getId')->willReturnCallback(function () use (&$data) { return (string) ($data['id'] ?? ''); });
        $entity->method('get')->willReturnCallback(function (string $name) use (&$data) { return $data[$name] ?? null; });
        $entity->method('set')->willReturnCallback(function ($name, $value = null) use (&$data, $entity) {
            foreach (is_array($name) ? $name : [$name => $value] as $key => $each) $data[$key] = $each;
            return $entity;
        });
        return $entity;
    }

    private function fits(Entity $row, array $where): bool
    {
        foreach ($where as $key => $value) {
            if (preg_match('/^(.+?)(>=|>)$/', $key, $m)) {
                $actual = (string) $row->get($m[1]);
                if ($actual === '' || ($m[2] === '>' ? $actual <= $value : $actual < $value)) return false;
                continue;
            }
            if ($row->get($key) !== $value) return false;
        }
        return true;
    }

    private function em(): EntityManager
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(function () {
            $repository = $this->createMock(RDBRepository::class);
            $repository->method('where')->willReturnCallback(function (array $where) {
                $rows = array_values(array_filter($this->rows, fn (Entity $row) => $this->fits($row, $where)));
                $select = $this->createMock(RDBSelectBuilder::class);
                // Newest first, as order('createdAt', 'DESC') asks.
                $select->method('order')->willReturnCallback(function () use (&$rows, $select) {
                    usort($rows, fn (Entity $a, Entity $b) => strcmp($b->getId(), $a->getId()));
                    return $select;
                });
                $select->method('find')->willReturnCallback(function () use (&$rows) { return new EntityCollection($rows); });
                $select->method('findOne')->willReturnCallback(function () use (&$rows) { return $rows[0] ?? null; });
                $select->method('count')->willReturnCallback(function () use (&$rows) { return count($rows); });
                return $select;
            });
            return $repository;
        });
        $em->method('createEntity')->willReturnCallback(function (string $type, array $values) {
            $row = $this->row(['id' => sprintf('c%03d', ++$this->ids), 'createdAt' => gmdate('Y-m-d H:i:s'), ...$values]);
            $this->rows[] = $row;
            return $row;
        });
        return $em;
    }

    private function codes(?CodeWhatsApp $whatsApp = null, ?TwilioVerify $sms = null, int $smsAfter = 20): PhoneCodes
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('secret')->willReturn('test-secret');
        $settings->method('smsAfterSeconds')->willReturn($smsAfter);
        if (!$whatsApp) {
            $whatsApp = $this->createMock(CodeWhatsApp::class);
            $whatsApp->method('send')->willReturnCallback(function (string $phone, string $code) {
                $this->sent[] = $code;
                return '+55119' . substr($phone, -8); // WhatsApp's own id for the number
            });
        }
        return new PhoneCodes($this->em(), $settings, $whatsApp, $sms ?? $this->createMock(TwilioVerify::class), $this->createMock(Log::class));
    }

    public function testTheCodeGoesByWhatsAppAndOnlyItsHmacIsKept(): void
    {
        $view = $this->codes()->send(PhoneCodes::SIGNUP, self::SIGNUP, self::PHONE);

        $this->assertCount(1, $this->sent);
        $code = $this->sent[0];
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertTrue($view['whatsApp']);
        $this->assertSame('+55 11 91234-5678', $view['display']);
        $this->assertStringNotContainsString($code, (string) json_encode($view), 'the screen never gets the code');
        $row = $this->rows[0];
        $this->assertNull($row->get('code'));
        $this->assertSame(hash_hmac('sha256', 'phone-code:' . $row->getId() . ':' . $code, 'test-secret'), $row->get('codeHash'));
    }

    public function testTheRightCodeProvesTheNumberAsWhatsAppKnowsItOnce(): void
    {
        $codes = $this->codes();
        $codes->send(PhoneCodes::SIGNUP, self::SIGNUP, self::PHONE);

        $this->assertSame('+5511912345678', $codes->check(PhoneCodes::SIGNUP, self::SIGNUP, substr($this->sent[0], 0, 3) . '-' . substr($this->sent[0], 3)));
        $this->expectExceptionObject(new CodeRejected(CodeRejected::CODE_EXPIRED));
        $codes->check(PhoneCodes::SIGNUP, self::SIGNUP, $this->sent[0]);
    }

    public function testFiveWrongCodesEndTheCode(): void
    {
        $codes = $this->codes();
        $codes->send(PhoneCodes::SIGNUP, self::SIGNUP, self::PHONE);
        $wrong = $this->sent[0] === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            try {
                $codes->check(PhoneCodes::SIGNUP, self::SIGNUP, $wrong);
                $this->fail('a wrong code passed');
            } catch (CodeRejected $e) {
                $this->assertSame(CodeRejected::INVALID_CODE, $e->reason);
            }
        }
        $this->expectExceptionObject(new CodeRejected(CodeRejected::TOO_MANY_ATTEMPTS));
        $codes->check(PhoneCodes::SIGNUP, self::SIGNUP, $this->sent[0]);
    }

    public function testANewCodeToTheSameNumberWaitsAMinuteButACorrectedNumberDoesNot(): void
    {
        $codes = $this->codes();
        $codes->send(PhoneCodes::SIGNUP, self::SIGNUP, self::PHONE);
        try {
            $codes->send(PhoneCodes::SIGNUP, self::SIGNUP, self::PHONE);
            $this->fail('resent at once');
        } catch (CodeRejected $e) {
            $this->assertSame(CodeRejected::RESEND_TOO_SOON, $e->reason);
        }

        $codes->send(PhoneCodes::SIGNUP, self::SIGNUP, '+5511988887777');
        $this->assertNotNull($this->rows[0]->get('usedAt'), 'the code to the old number stops working');
        $this->assertSame('+5511988887777', $codes->pending(PhoneCodes::SIGNUP, self::SIGNUP)['phone']);
    }

    public function testANumberGetsAtMostFiveCodesAnHourWhoeverAsks(): void
    {
        $codes = $this->codes();
        for ($i = 1; $i <= 5; $i++) $codes->send(PhoneCodes::SIGNUP, ['signupId' => "s{$i}"], self::PHONE);
        $this->expectExceptionObject(new CodeRejected(CodeRejected::RATE_LIMITED));
        $codes->send(PhoneCodes::SIGNUP, ['signupId' => 's6'], self::PHONE);
    }

    public function testWhenMetaRefusesTheNumberTheCodeGoesBySms(): void
    {
        $whatsApp = $this->createMock(CodeWhatsApp::class);
        $whatsApp->method('send')->willThrowException(new RuntimeException('Meta API: invalid number'));
        $sms = $this->createMock(TwilioVerify::class);
        $sms->expects($this->once())->method('start')->with(self::PHONE)->willReturn('VE' . str_repeat('a', 32));

        $view = $this->codes($whatsApp, $sms)->send(PhoneCodes::SIGNUP, self::SIGNUP, self::PHONE);
        $this->assertFalse($view['whatsApp']);
        $this->assertTrue($view['sms']);
    }

    public function testWhenNeitherChannelSendsThePersonIsTold(): void
    {
        $whatsApp = $this->createMock(CodeWhatsApp::class);
        $whatsApp->method('send')->willThrowException(new RuntimeException('down'));
        $sms = $this->createMock(TwilioVerify::class);
        $sms->method('start')->willThrowException(new RuntimeException('down'));

        $this->expectExceptionObject(new CodeRejected(CodeRejected::SEND_FAILED));
        $this->codes($whatsApp, $sms)->send(PhoneCodes::SIGNUP, self::SIGNUP, self::PHONE);
    }

    public function testTheSmsWaitsForTheWhatsAppOneAndItsCodeAlsoProvesTheNumber(): void
    {
        $this->codes()->send(PhoneCodes::SIGNUP, self::SIGNUP, self::PHONE);
        try {
            $this->codes()->sms(PhoneCodes::SIGNUP, self::SIGNUP);
            $this->fail('SMS before the WhatsApp code had time to arrive');
        } catch (CodeRejected $e) {
            $this->assertSame(CodeRejected::SMS_TOO_SOON, $e->reason);
        }

        $sms = $this->createMock(TwilioVerify::class);
        $sms->method('start')->willReturn('VE' . str_repeat('b', 32));
        $sms->method('check')->willReturnCallback(fn (string $phone, string $code) => $phone === self::PHONE && $code === '424242');
        $codes = $this->codes(null, $sms, 0);
        $this->assertTrue($codes->sms(PhoneCodes::SIGNUP, self::SIGNUP, '203.0.113.9')['sms']);
        $this->assertSame('+5511912345678', $codes->check(PhoneCodes::SIGNUP, self::SIGNUP, '424242'));
    }

    public function testSettingsCodesBelongToThePersonInThatWorkspace(): void
    {
        $codes = $this->codes();
        $mine = ['userId' => 'u1', 'chatwootAccountId' => 'a1'];
        $codes->send(PhoneCodes::SUBSCRIPTION, $mine, self::PHONE);

        $this->assertNull($codes->pending(PhoneCodes::SUBSCRIPTION, ['userId' => 'u2', 'chatwootAccountId' => 'a1']));
        $this->assertNull($codes->pending(PhoneCodes::SIGNUP, self::SIGNUP));
        $this->assertNotNull($codes->pending(PhoneCodes::SUBSCRIPTION, $mine));
    }
}
