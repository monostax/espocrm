<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Services\CustomFieldsBag;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;
use stdClass;

class CustomFieldsBagTest extends TestCase
{
    private CustomFieldsBag $bag;

    protected function setUp(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(
            static function (array $path, mixed $default = null): mixed {
                if ($path === ['app', 'customFields', 'attributeName']) {
                    return 'customFields';
                }

                if ($path === ['app', 'customFields', 'entityTypeList']) {
                    return ['Contact', 'Lead', 'Account', 'Opportunity'];
                }

                return $default;
            }
        );

        $this->bag = new CustomFieldsBag($metadata);
    }

    public function testIsBagAttribute(): void
    {
        $this->assertTrue($this->bag->isBagAttribute('customFields'));
        $this->assertTrue($this->bag->isBagAttribute('customFields.plan'));
        $this->assertTrue($this->bag->isBagAttribute('customFields.billing.plan'));
        $this->assertFalse($this->bag->isBagAttribute('status'));
        $this->assertFalse($this->bag->isBagAttribute('cFoo'));
    }

    public function testValueKeyFromAttribute(): void
    {
        $this->assertNull($this->bag->valueKeyFromAttribute('customFields'));
        $this->assertSame('plan', $this->bag->valueKeyFromAttribute('customFields.plan'));
        $this->assertSame(
            'billing.plan',
            $this->bag->valueKeyFromAttribute('customFields.billing.plan')
        );
        $this->assertNull($this->bag->valueKeyFromAttribute('status'));
    }

    public function testExpandUpdateFieldsMergesDottedAndNested(): void
    {
        $expanded = $this->bag->expandUpdateFields([
            'description' => 'hi',
            'customFields.plan' => 'pro',
            'customFields' => ['billing.plan' => 'enterprise', 'score' => 10],
            'customFields.score' => 20,
        ]);

        $this->assertSame(['description' => 'hi'], $expanded['fields']);
        $this->assertSame(
            [
                'plan' => 'pro',
                'billing.plan' => 'enterprise',
                'score' => 20,
            ],
            $expanded['bagPatch']
        );
    }

    public function testMergeBagNullRemovesKey(): void
    {
        $merged = $this->bag->mergeBag(
            ['plan' => 'pro', 'keep' => 1],
            ['plan' => null, 'score' => 2]
        );

        $this->assertSame(['keep' => 1, 'score' => 2], $merged);
    }

    public function testPartitionWhereSeparatesBagAndNative(): void
    {
        $partition = $this->bag->partitionWhere([
            ['type' => 'isFalse', 'attribute' => 'doNotCall'],
            ['type' => 'equals', 'attribute' => 'customFields.plan', 'value' => 'pro'],
            [
                'type' => 'and',
                'value' => [
                    ['type' => 'equals', 'attribute' => 'customFields.score', 'value' => 1],
                    ['type' => 'equals', 'attribute' => 'status', 'value' => 'New'],
                ],
            ],
        ]);

        $this->assertCount(1, $partition['native']);
        $this->assertSame('doNotCall', $partition['native'][0]['attribute']);
        $this->assertCount(2, $partition['bag']);
    }

    public function testMatchWhereItemsAgainstBag(): void
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnCallback(
            static function (string $attr): mixed {
                if ($attr === 'customFields') {
                    return (object) [
                        'plan' => 'pro',
                        'billing.plan' => 'enterprise',
                        'score' => 10,
                    ];
                }

                if ($attr === 'doNotCall') {
                    return false;
                }

                return null;
            }
        );
        $entity->method('hasAttribute')->willReturn(true);

        $this->assertTrue($this->bag->matchWhereItems([
            ['type' => 'equals', 'attribute' => 'customFields.plan', 'value' => 'pro'],
            ['type' => 'equals', 'attribute' => 'customFields.billing.plan', 'value' => 'enterprise'],
            ['type' => 'greaterThanOrEquals', 'attribute' => 'customFields.score', 'value' => 10],
            ['type' => 'isFalse', 'attribute' => 'doNotCall'],
        ], $entity));

        $this->assertFalse($this->bag->matchWhereItems([
            ['type' => 'equals', 'attribute' => 'customFields.plan', 'value' => 'free'],
        ], $entity));
    }

    public function testNormalizeBagFromStdClass(): void
    {
        $obj = new stdClass();
        $obj->{'address.city'} = 'SP';
        $obj->plan = 'pro';

        $this->assertSame(
            ['address.city' => 'SP', 'plan' => 'pro'],
            $this->bag->normalizeBag($obj)
        );
    }
}
