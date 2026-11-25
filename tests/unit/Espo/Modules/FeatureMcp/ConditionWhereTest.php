<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace tests\unit\Espo\Modules\FeatureMcp;

use Espo\Modules\FeatureMcp\Tools\SavedFilters\ConditionWhere;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP port gives the where items the Chatwoot browser builds for the same saved conditions. Fixtures come from
 * the browser code (fixtures/generate-condition-where.mjs).
 */
class ConditionWhereTest extends TestCase
{
    /** @return iterable<string, array{string, array<mixed>, array<mixed>}> */
    public static function cases(): iterable
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/fixtures/condition-where.json'), true);

        foreach ($cases as $index => $case) {
            yield "$index {$case['entityType']}" => [$case['entityType'], $case['conditions'], $case['where']];
        }
    }

    /**
     * @param array<mixed> $conditions
     * @param array<mixed> $expected
     */
    #[DataProvider('cases')]
    public function testMatchesTheBrowser(string $entityType, array $conditions, array $expected): void
    {
        $where = (new ConditionWhere())->build($entityType, $conditions, 'America/Sao_Paulo');

        $this->assertSame(json_encode($expected), json_encode($where));
    }

    public function testUnknownTypeBuildsNothing(): void
    {
        $this->assertFalse(ConditionWhere::supports('Lead'));
        $this->assertSame([], (new ConditionWhere())->build('Lead', [['attributeKey' => 'name', 'filterOperator' => 'equal_to', 'values' => 'x']], null));
    }
}
