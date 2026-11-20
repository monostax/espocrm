<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\Chatwoot;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Modules\Chatwoot\Rebuild\BackfillWhatsAppRemovals;
use Espo\Modules\Chatwoot\Tools\ContactReconciler;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;

/** Runs real hooks and field savers; never calls a messaging provider. */
class WhatsAppRemovalTest extends TestCase
{
    public function testRemoveRepairAndReaddOnlyAffectTheOwnersPhoneLink(): void
    {
        if (getenv('ESPO_WA_REMOVAL_TEST') !== '1') {
            self::markTestSkipped('Requires an isolated Espo test database with the schema installed.');
        }

        $app = new Application(new ApplicationParams(noErrorHandler: true));
        $em = $app->getContainer()->get('entityManager');
        $app->setupSystemUser();
        $factory = $app->getInjectableFactory();
        $tid = $em->createEntity('Tenant', ['name' => 'Removal test'], ['skipHooks' => true])->getId();
        $contact = $em->createEntity('Contact', ['tenantId' => $tid, 'lastName' => 'One'], ['skipHooks' => true]);
        $other = $em->createEntity('Contact', ['tenantId' => $tid, 'lastName' => 'Two'], ['skipHooks' => true]);
        $id = $contact->getId();
        $removed = '+5511991234567';
        $kept = '+5511987654321';
        $row = static fn ($phone, $primary) => (object) ['channelType' => 'whatsapp', 'sourceId' => $phone, 'isPrimary' => $primary];
        $contact->set('channelIdentitiesData', [$row($removed, true), $row($kept, false)]);
        $em->saveEntity($contact, ['silent' => true]);
        $other->set('phoneNumberData', [(object) ['phoneNumber' => $removed, 'primary' => true]]);
        $em->saveEntity($other, ['silent' => true, 'skipChannelIdentitiesSave' => true]);
        $native = fn (Entity $person) => array_column($em->getRepository('PhoneNumber')->getPhoneNumberData($person), 'phoneNumber');
        self::assertSame([$removed, $kept], $native($contact));

        // Same full-list edit as removing a WhatsApp on the Contact screen.
        $save = function (array $list) use ($em, $id): Entity {
            $person = $em->getEntityById('Contact', $id);
            $person->set('channelIdentitiesData', $list);
            $em->saveEntity($person, ['silent' => true]);
            return $em->getEntityById('Contact', $id);
        };
        $keep = $row($kept, true);
        $keep->id = $em->getRDBRepository('ContactChannelIdentity')->where(['contactId' => $id, 'sourceId' => $kept])->findOne()->getId();
        $contact = $save([$keep]);
        self::assertSame([$kept], $native($contact));
        self::assertSame($kept, $contact->get('phoneNumber'));
        self::assertSame([$removed], $native($other));
        $outbound = $factory->create(JourneyWhatsAppOutbound::class);
        self::assertSame([$kept], $outbound->resolvePhones($contact));
        self::assertSame([], $outbound->resolvePhones($contact, $removed));
        self::assertNull($factory->create(ContactReconciler::class)
            ->upsertIdentity($id, $tid, 'whatsapp', $removed, null, null, null));

        // Recreate the pre-fix split state and prove both the send guard and repair.
        $contact->set('phoneNumberData', [
            (object) ['phoneNumber' => $removed, 'primary' => true], (object) ['phoneNumber' => $kept],
        ]);
        $em->saveEntity($contact, ['silent' => true, 'skipChannelIdentitiesSave' => true]);
        self::assertSame([$kept], $outbound->resolvePhones($contact));
        $em->getPDO()->prepare('DELETE FROM contact_wa_removal WHERE contact_id = ?')->execute([$id]);
        $backfill = $factory->create(BackfillWhatsAppRemovals::class);
        $hooks = $factory->create(\Espo\Core\Hook\Control::class);
        $hooks->disable();
        try {
            $backfill->process();
            $backfill->process();
        } finally {
            $hooks->enable();
        }
        self::assertSame([$kept], $native($contact));
        self::assertSame([$removed], $native($other));

        $contact = $save([$keep, $row($removed, false)]);
        self::assertSame([$kept, $removed], $native($contact));
        self::assertSame([$kept, $removed], $outbound->resolvePhones($contact));
        $contact = $save([]);
        self::assertSame([], $native($contact));
        self::assertEmpty($contact->get('phoneNumber'));
        self::assertSame([], $outbound->resolvePhones($contact));
    }
}
