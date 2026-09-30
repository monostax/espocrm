<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Tools\CustomField;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Global\Tools\CustomField\Conditions;
use PHPUnit\Framework\TestCase;

class ConditionsTest extends TestCase
{
    public function testSharedPhpAndJavascriptOperatorContract(): void
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__, 6) . '/fixtures/custom-field-conditions.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            Conditions::validate($case['condition']);
            $this->assertSame($case['expected'], Conditions::evaluate($case['condition'], $case['context'], $case['partial'] ?? false), $case['name']);
        }
    }

    public function testNestedConditionsAndPartialStageContext(): void
    {
        $rule = ['all' => [
            ['attribute' => 'funnelId', 'operator' => 'equals', 'value' => 'solar'],
            ['any' => [
                ['attribute' => 'opportunityStageId', 'operator' => 'in', 'value' => ['proposal', 'handover']],
                ['attribute' => 'status', 'operator' => 'equals', 'value' => 'Won'],
            ]],
        ]];
        Conditions::validate($rule);
        $this->assertTrue(Conditions::evaluate(null, []));
        $this->assertTrue(Conditions::evaluate($rule, ['funnelId' => 'solar', 'opportunityStageId' => 'proposal']));
        $this->assertFalse(Conditions::evaluate($rule, ['funnelId' => 'other', 'status' => 'Won']));
        $this->assertFalse(Conditions::evaluate($rule, ['funnelId' => 'solar']));
        $this->assertNull(Conditions::evaluate($rule, ['funnelId' => 'solar'], true));
        $this->assertFalse(Conditions::evaluate($rule, ['funnelId' => 'other'], true));
        $this->assertCount(3, Conditions::leaves($rule));
    }

    public function testRequirednessIsGatedByApplicability(): void
    {
        $field = [
            'appliesWhen' => ['attribute' => 'funnelId', 'operator' => 'equals', 'value' => 'solar'],
            'requiredWhen' => ['attribute' => 'status', 'operator' => 'equals', 'value' => 'Won'],
        ];
        $this->assertTrue(Conditions::required($field, ['funnelId' => 'solar', 'status' => 'Won']));
        $this->assertFalse(Conditions::required($field, ['funnelId' => 'solar', 'status' => 'Open']));
        $this->assertFalse(Conditions::required($field + ['isRequired' => true], ['funnelId' => 'other', 'status' => 'Won']));
        $this->assertTrue(Conditions::required($field + ['isRequired' => true], ['funnelId' => 'solar']));
        $this->assertFalse(Conditions::required([], []));
    }

    public function testMalformedAndUnsupportedRulesAreRejected(): void
    {
        foreach ([[], (object) [], ['all' => []], ['all' => [null]],
            ['attribute' => 'account.industry', 'operator' => 'equals', 'value' => 'Healthcare'],
            ['attribute' => 'customFields.approved', 'operator' => 'isTrue'],
            ['attribute' => 'tags', 'operator' => 'containsAny', 'value' => []],
            ['attribute' => 'website', 'operator' => 'isEmpty', 'value' => ''],
            ['attribute' => 'status', 'operator' => 'eval', 'value' => 'Won'],
            ['attribute' => 'status', 'operator' => 'in', 'value' => []],
            ['attribute' => 'status', 'operator' => 'equals', 'value' => ['Won']],
        ] as $rule) {
            try {
                Conditions::validate($rule);
                $this->fail('Accepted an invalid rule.');
            } catch (BadRequest) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
