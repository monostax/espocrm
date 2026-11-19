<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureAutomation\Services\ContactVerification;
use Espo\Modules\FeatureCredits\Verification\Operations;
use PHPUnit\Framework\TestCase;

class ContactVerificationTest extends TestCase
{
    private const SOURCE = ['kind' => 'purchased', 'receiptId' => 'att1', 'index' => 0];

    public function testResumedItemReadsTheOperationItStarted(): void
    {
        $operations = $this->createMock(Operations::class);
        $operations->expects($this->once())->method('status')
            ->with(['tenantId' => 't1', 'contactId' => 'c1', 'requestKey' => 'automation:k'], 'whatsapp_registration')
            ->willReturn(['operation' => ['id' => 'op1', 'state' => 'pending']]);
        $operations->expects($this->never())->method('context');
        $operations->expects($this->never())->method('start');

        $result = (new ContactVerification($operations))
            ->startOrRead('t1', 'c1', 'whatsapp_registration', self::SOURCE, 'automation:k');

        $this->assertSame(['operation' => ['id' => 'op1', 'state' => 'pending']], $result);
    }

    public function testNewCheckStartsAsABulkAdmissionAtTheListedPrice(): void
    {
        $revision = str_repeat('a', 64);
        $operations = $this->createMock(Operations::class);
        $operations->method('status')->willThrowException(new NotFound());
        $operations->expects($this->once())->method('context')
            ->willReturnCallback(function (\stdClass $input, string $kind) use ($revision) {
                $this->assertSame('phone_identity', $kind);
                $this->assertEquals([(object) (self::SOURCE + ['key' => 'source'])], $input->sources);

                return ['rows' => ['source' => [
                    'eligible' => true,
                    'contextRevision' => $revision,
                    'actions' => ['requiresPaidRefresh' => false],
                ]]];
            });
        $operations->expects($this->once())->method('start')
            ->willReturnCallback(function (\stdClass $input, string $kind, bool $bulk) use ($revision) {
                $this->assertSame('phone_identity', $kind);
                $this->assertTrue($bulk);
                $this->assertSame('automation:k', $input->requestKey);
                $this->assertSame($revision, $input->contextRevision);
                $this->assertSame('8.0000', $input->maxChargeCredits);
                $this->assertFalse($input->refresh);
                $this->assertEquals((object) self::SOURCE, $input->source);

                return ['operation' => ['id' => 'op2', 'state' => 'pending']];
            });

        $result = (new ContactVerification($operations))
            ->startOrRead('t1', 'c1', 'phone_identity', self::SOURCE, 'automation:k');

        $this->assertSame('op2', $result['operation']['id']);
    }

    public function testExpiredEvidenceIsRefreshed(): void
    {
        $operations = $this->createMock(Operations::class);
        $operations->method('status')->willThrowException(new NotFound());
        $operations->method('context')->willReturn(['rows' => ['source' => [
            'eligible' => true,
            'contextRevision' => str_repeat('b', 64),
            'actions' => ['requiresPaidRefresh' => true],
        ]]]);
        $operations->expects($this->once())->method('start')
            ->willReturnCallback(function (\stdClass $input) {
                $this->assertTrue($input->refresh);

                return ['operation' => ['id' => 'op3', 'state' => 'pending']];
            });

        (new ContactVerification($operations))->startOrRead('t1', 'c1', 'whatsapp_registration', self::SOURCE, 'automation:k');
    }

    public function testIneligibleValueIsReportedWithoutStarting(): void
    {
        $operations = $this->createMock(Operations::class);
        $operations->method('status')->willThrowException(new NotFound());
        $operations->method('context')->willReturn(['rows' => ['source' => [
            'eligible' => false,
            'reason' => 'missing_name',
            'contextRevision' => null,
        ]]]);
        $operations->expects($this->never())->method('start');

        $result = (new ContactVerification($operations))
            ->startOrRead('t1', 'c1', 'phone_identity', self::SOURCE, 'automation:k');

        $this->assertSame(['unavailable' => 'missing_name'], $result);
    }
}
