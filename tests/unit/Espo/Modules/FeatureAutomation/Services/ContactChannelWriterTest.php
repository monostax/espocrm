<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Modules\FeatureAutomation\Services\ContactChannelWriter;
use Espo\Modules\FeatureCredits\Verification\Access;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\Repositories\EmailAddress as EmailAddressRepository;
use Espo\Repositories\PhoneNumber as PhoneNumberRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class ContactChannelWriterTest extends TestCase
{
    private ?\stdClass $written = null;

    public function testFoundPhoneBecomesTheFirstPrimaryPhone(): void
    {
        $outcome = $this->writer(['value' => '+5511999990000', 'ownershipRejected' => false], phones: [])
            ->add('tenant1', 'contact1', 'phone', $this->source());

        $this->assertSame('added', $outcome);
        $this->assertEquals((object) ['phoneNumberData' => [(object) [
            'phoneNumber' => '+5511999990000',
            'type' => 'Mobile',
            'primary' => true,
            'optOut' => false,
            'invalid' => false,
        ]]], $this->written);
    }

    public function testFoundPhoneIsAppendedNextToExistingOnes(): void
    {
        $existing = (object) ['phoneNumber' => '+5511888880000', 'type' => 'Office', 'primary' => true, 'optOut' => false, 'invalid' => false];

        $this->writer(['value' => '+5511999990000', 'ownershipRejected' => false], phones: [$existing])
            ->add('tenant1', 'contact1', 'phone', $this->source());

        $this->assertCount(2, $this->written->phoneNumberData);
        $this->assertSame($existing, $this->written->phoneNumberData[0]);
        $this->assertFalse($this->written->phoneNumberData[1]->primary);
    }

    public function testPhoneAlreadyOnTheContactInAnotherFormatIsLeftAlone(): void
    {
        $existing = (object) ['phoneNumber' => '55 11 99999-0000', 'type' => 'Mobile', 'primary' => true];

        $outcome = $this->writer(['value' => '+5511999990000', 'ownershipRejected' => false], phones: [$existing])
            ->add('tenant1', 'contact1', 'phone', $this->source());

        $this->assertSame('already_present', $outcome);
        $this->assertNull($this->written);
    }

    public function testDeliverableEmailIsAddedToTheEmailList(): void
    {
        $outcome = $this->writer(['value' => 'ana@example.com', 'ownershipRejected' => false], emails: [])
            ->add('tenant1', 'contact1', 'email', $this->source());

        $this->assertSame('added', $outcome);
        $this->assertSame('ana@example.com', $this->written->emailAddressData[0]->emailAddress);
        $this->assertTrue($this->written->emailAddressData[0]->primary);
    }

    public function testValueThePersonRejectedIsNeverAdded(): void
    {
        $outcome = $this->writer(['value' => '+5511999990000', 'ownershipRejected' => true])
            ->add('tenant1', 'contact1', 'phone', $this->source());

        $this->assertSame('rejected', $outcome);
        $this->assertNull($this->written);
    }

    public function testIdentityOwnedByAnotherContactIsAConflictNotAFailure(): void
    {
        $outcome = $this->writer(['value' => '+5511999990000', 'ownershipRejected' => false], phones: [], updateThrows: new Conflict('taken'))
            ->add('tenant1', 'contact1', 'phone', $this->source());

        $this->assertSame('conflict', $outcome);
    }

    public function testRunAsUserWithoutPhoneEditAccessCannotAdd(): void
    {
        $this->expectException(Forbidden::class);

        $this->writer(['value' => '+5511999990000', 'ownershipRejected' => false], phones: [], fieldEdit: false)
            ->add('tenant1', 'contact1', 'phone', $this->source());
    }

    private function source(): array
    {
        return ['kind' => 'purchased', 'receiptId' => 'att1', 'index' => 0];
    }

    private function writer(
        array $resolved,
        array $phones = [],
        array $emails = [],
        bool $fieldEdit = true,
        ?\Throwable $updateThrows = null,
    ): ContactChannelWriter {
        $this->written = null;
        $contact = $this->createMock(Entity::class);

        $access = $this->createMock(Access::class);
        $access->method('contact')->willReturn($contact);
        $access->method('resolveSource')->willReturn($resolved + ['rawPhone' => '', 'e164' => null, 'phoneReason' => null]);

        $acl = $this->createMock(Acl::class);
        $acl->method('check')->willReturn(true);
        $acl->method('checkField')->willReturn($fieldEdit);
        $acl->method('checkScope')->willReturn(true);

        $phoneRepository = $this->createMock(PhoneNumberRepository::class);
        $phoneRepository->method('getPhoneNumberData')->willReturn($phones);
        $emailRepository = $this->createMock(EmailAddressRepository::class);
        $emailRepository->method('getEmailAddressData')->willReturn($emails);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getPDO')->willReturn($this->createMock(PDO::class));
        $entityManager->method('getRDBRepository')->willReturnCallback(
            fn (string $type) => $type === 'PhoneNumber' ? $phoneRepository : $emailRepository
        );

        $service = $this->createMock(Service::class);
        $service->method('update')->willReturnCallback(function (string $id, \stdClass $data) use ($updateThrows) {
            if ($updateThrows) {
                throw $updateThrows;
            }
            $this->assertSame('contact1', $id);
            $this->written = $data;

            return $this->createMock(\Espo\Core\Record\UpdateResult::class);
        });
        $services = $this->createMock(ServiceContainer::class);
        $services->method('get')->with('Contact')->willReturn($service);

        return new ContactChannelWriter($entityManager, $access, $acl, $services);
    }
}
