<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Rebuild;

use Espo\Modules\Chatwoot\Rebuild\BackfillChannelIdentities;
use PHPUnit\Framework\TestCase;

/** Pass 1 must converge: re-running it on unchanged data saves nothing. */
final class BackfillChannelIdentitiesSettleTest extends TestCase
{
    private static function plan(string $contact, string $source = '+5534991234567', ?string $label = null, string $channel = 'whatsapp'): array
    {
        return ['contactId' => $contact, 'tenantId' => 'tenant-a', 'channelType' => $channel, 'sourceId' => $source,
            'label' => $label, 'chatwootAccountId' => 'account-1', 'chatwootInboxId' => null];
    }

    /**
     * upsertIdentity's update rules (contactId always, label when non-empty),
     * applied to an in-memory store. Returns the number of saves.
     */
    private static function apply(array $plans, array &$store): int
    {
        $saves = 0;
        foreach ($plans as $plan) {
            $key = $plan['tenantId'] . '|' . $plan['channelType'] . '|' . $plan['sourceId'];
            if (!isset($store[$key])) {
                $store[$key] = ['contactId' => $plan['contactId'], 'label' => $plan['label']];
                continue;
            }
            $changed = false;
            if ($store[$key]['contactId'] !== $plan['contactId']) {
                $store[$key]['contactId'] = $plan['contactId'];
                $changed = true;
            }
            if ($plan['label'] && $store[$key]['label'] !== $plan['label']) {
                $store[$key]['label'] = $plan['label'];
                $changed = true;
            }
            $saves += (int) $changed;
        }
        return $saves;
    }

    private static function rebuild(array $plans, array &$store): int
    {
        [$settled] = BackfillChannelIdentities::settle($plans, static function (array $plan) use (&$store): ?array {
            return $store[$plan['tenantId'] . '|' . $plan['channelType'] . '|' . $plan['sourceId']] ?? null;
        });
        return self::apply($settled, $store);
    }

    public function testDuplicateContactsNoLongerMoveTheIdentityEveryRun(): void
    {
        $plans = [self::plan('contact-b'), self::plan('contact-a')];
        $store = ['tenant-a|whatsapp|+5534991234567' => ['contactId' => 'contact-a', 'label' => null]];
        // Before: applied in row order, every run saved twice and ended where it began.
        $unsettled = $store;
        self::assertSame(2, self::apply($plans, $unsettled));
        self::assertSame('contact-a', $unsettled['tenant-a|whatsapp|+5534991234567']['contactId']);
        // After: the current owner is kept and nothing is saved.
        self::assertSame(0, self::rebuild($plans, $store));
        self::assertSame(0, self::rebuild(array_reverse($plans), $store));
        self::assertSame('contact-a', $store['tenant-a|whatsapp|+5534991234567']['contactId']);
    }

    public function testAnOwnerThatIsNoLongerAClaimantGivesWayDeterministicallyThenConverges(): void
    {
        $plans = [self::plan('contact-c'), self::plan('contact-b')];
        $store = ['tenant-a|whatsapp|+5534991234567' => ['contactId' => 'contact-gone', 'label' => null]];
        self::assertSame(1, self::rebuild($plans, $store));
        self::assertSame('contact-b', $store['tenant-a|whatsapp|+5534991234567']['contactId']);
        self::assertSame(0, self::rebuild(array_reverse($plans), $store));
    }

    public function testNewContestedIdentityIsCreatedOnceForTheSmallestClaimant(): void
    {
        $store = [];
        self::rebuild([self::plan('contact-z'), self::plan('contact-m')], $store);
        self::assertSame('contact-m', $store['tenant-a|whatsapp|+5534991234567']['contactId']);
        self::assertSame(0, self::rebuild([self::plan('contact-m'), self::plan('contact-z')], $store));
    }

    public function testCompetingInboxLabelsKeepTheCurrentLabel(): void
    {
        $plans = [self::plan('contact-a', label: 'Sales'), self::plan('contact-a', label: 'Support'), self::plan('contact-a')];
        $store = ['tenant-a|whatsapp|+5534991234567' => ['contactId' => 'contact-a', 'label' => 'Support']];
        self::assertSame(0, self::rebuild($plans, $store));
        self::assertSame('Support', $store['tenant-a|whatsapp|+5534991234567']['label']);
    }

    public function testUncontestedPlansPassThroughUnchanged(): void
    {
        $plans = [self::plan('contact-a'), self::plan('contact-a', label: 'Sales'),
            self::plan('contact-b', 'b@example.test', null, 'email')];
        [$settled, $contested] = BackfillChannelIdentities::settle($plans, static fn () => null);
        self::assertSame($plans, $settled);
        self::assertSame(0, $contested);
    }

    public function testIdentitiesAreSettledPerTenantAndChannel(): void
    {
        $plans = [self::plan('contact-a'), ['tenantId' => 'tenant-b'] + self::plan('contact-b'),
            self::plan('contact-c', channel: 'sms')];
        [$settled, $contested] = BackfillChannelIdentities::settle($plans, static fn () => null);
        self::assertSame(0, $contested);
        self::assertSame(['contact-a', 'contact-b', 'contact-c'], array_column($settled, 'contactId'));
    }
}
