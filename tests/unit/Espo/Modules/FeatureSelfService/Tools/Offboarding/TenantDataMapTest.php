<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService\Tools\Offboarding;

use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureSelfService\Tools\Offboarding\TenantDataMap;
use PHPUnit\Framework\TestCase;

class TenantDataMapTest extends TestCase
{
    public function testChildrenComeBeforeTheTypesTheyBelongTo(): void
    {
        $order = TenantDataMap::childrenFirst(
            ['InitiativeType', 'Initiative', 'InitiativeStage', 'Contact', 'Account', 'Opportunity'],
            [
                'Initiative' => ['InitiativeStage', 'InitiativeType'],
                'InitiativeStage' => ['InitiativeType'],
                'Contact' => ['Account'],
                'Opportunity' => ['Account', 'Contact'],
            ],
        );

        self::assertCount(6, $order);
        $at = array_flip($order);
        self::assertLessThan($at['InitiativeStage'], $at['Initiative']);
        self::assertLessThan($at['InitiativeType'], $at['InitiativeStage']);
        self::assertLessThan($at['Account'], $at['Contact']);
        self::assertLessThan($at['Contact'], $at['Opportunity']);
    }

    public function testCyclesAndSelfLinksStillListEveryTypeOnce(): void
    {
        $order = TenantDataMap::childrenFirst(['A', 'B', 'C'], ['A' => ['B', 'A'], 'B' => ['A'], 'C' => ['C']]);

        self::assertEqualsCanonicalizing(['A', 'B', 'C'], $order);
    }

    public function testParentsOutsideTheSetAreIgnored(): void
    {
        self::assertSame(['Contact'], TenantDataMap::childrenFirst(['Contact'], ['Contact' => ['Account']]));
    }

    public function testTenantScopedTypesSkipBillingVirtualTypesAndTheTypesWithTheirOwnSteps(): void
    {
        $map = new TenantDataMap($this->metadata([
            'scopes' => [
                'Contact' => ['entity' => true, 'module' => 'Global'],
                'Account' => ['entity' => true, 'module' => 'Global'],
                'CreditTransaction' => ['entity' => true, 'module' => 'FeatureCredits'],
                'TenantAiBillingRate' => ['entity' => true, 'module' => 'Chatwoot'],
                'ChatwootAccount' => ['entity' => true, 'module' => 'Chatwoot'],
                'SelfServiceOffboarding' => ['entity' => true, 'module' => 'FeatureSelfService'],
                'Note' => ['entity' => true],
                'Disabled' => ['entity' => false],
                'AgentMode' => ['entity' => true, 'isVirtual' => true],
                'Imported' => ['entity' => true],
            ],
            'entityDefs' => [
                'Contact' => ['links' => [
                    'tenant' => ['type' => 'belongsTo', 'entity' => 'Tenant'],
                    'account' => ['type' => 'belongsTo', 'entity' => 'Account'],
                ]],
                'Account' => ['links' => ['workspace' => ['type' => 'belongsTo', 'entity' => 'Tenant']]],
                'CreditTransaction' => ['links' => ['tenant' => ['type' => 'belongsTo', 'entity' => 'Tenant']]],
                'TenantAiBillingRate' => ['links' => ['tenant' => ['type' => 'belongsTo', 'entity' => 'Tenant']]],
                'ChatwootAccount' => ['links' => ['tenant' => ['type' => 'belongsTo', 'entity' => 'Tenant']]],
                'SelfServiceOffboarding' => ['links' => ['tenant' => ['type' => 'belongsTo', 'entity' => 'Tenant']]],
                'Note' => ['links' => ['parent' => ['type' => 'belongsToParent']]],
                'Disabled' => ['links' => ['tenant' => ['type' => 'belongsTo', 'entity' => 'Tenant']]],
                'AgentMode' => ['links' => ['tenant' => ['type' => 'belongsTo', 'entity' => 'Tenant']]],
                'Imported' => ['skipRebuild' => true, 'links' => ['tenant' => ['type' => 'belongsTo', 'entity' => 'Tenant']]],
            ],
        ]));

        self::assertSame([
            ['entityType' => 'Contact', 'column' => 'tenantId'],
            ['entityType' => 'Account', 'column' => 'workspaceId'],
        ], $map->tenantScopedTypes());

        self::assertTrue($map->isRetained('CreditTransaction'));
        self::assertTrue($map->isRetained('ChatwootMachineIdentity'));
        self::assertFalse($map->isRetained('Contact'));
    }

    public function testChatwootMirrorPutsConversationsAndContactsFirst(): void
    {
        $map = new TenantDataMap($this->metadata([
            'entityDefs' => [
                'ChatwootAccount' => [
                    'cascadeDelete' => ['links' => ['inboxes', 'contacts', 'conversations', 'labels', 'unknown']],
                    'links' => [
                        'inboxes' => ['type' => 'hasMany', 'entity' => 'ChatwootInbox', 'foreign' => 'chatwootAccount'],
                        'contacts' => ['type' => 'hasMany', 'entity' => 'ChatwootContact', 'foreign' => 'chatwootAccount'],
                        'conversations' => ['type' => 'hasMany', 'entity' => 'ChatwootConversation', 'foreign' => 'chatwootAccount'],
                        'labels' => ['type' => 'hasMany', 'entity' => 'ChatwootLabel', 'foreignKey' => 'accountId'],
                    ],
                ],
                'ChatwootInbox' => ['links' => ['chatwootAccount' => ['type' => 'belongsTo', 'entity' => 'ChatwootAccount', 'foreign' => 'inboxes']]],
                'ChatwootContact' => ['links' => ['chatwootAccount' => ['type' => 'belongsTo', 'entity' => 'ChatwootAccount', 'foreign' => 'contacts']]],
                'ChatwootConversation' => ['links' => ['chatwootAccount' => ['type' => 'belongsTo', 'entity' => 'ChatwootAccount', 'foreign' => 'conversations']]],
            ],
        ]));

        self::assertSame([
            ['entityType' => 'ChatwootConversation', 'column' => 'chatwootAccountId'],
            ['entityType' => 'ChatwootContact', 'column' => 'chatwootAccountId'],
            ['entityType' => 'ChatwootInbox', 'column' => 'chatwootAccountId'],
            ['entityType' => 'ChatwootLabel', 'column' => 'accountId'],
        ], $map->chatwootAccountChildren());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function metadata(array $data): Metadata
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(function ($path) use ($data) {
            $value = $data;

            foreach ((array) $path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    return null;
                }

                $value = $value[$key];
            }

            return $value;
        });

        return $metadata;
    }
}
