<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;
use PHPUnit\Framework\TestCase;

class RestrictedFormulaRunnerTest extends TestCase
{
    private RestrictedFormulaRunner $runner;

    protected function setUp(): void
    {
        $manager = $this->createMock(FormulaManager::class);
        $manager->method('run')->willReturn(true);

        $this->runner = new RestrictedFormulaRunner($manager);
    }

    public function testConditionAllowsComparisonAndString(): void
    {
        $this->runner->assertScriptAllowed(
            'string\\concatenate("a", entity\\attribute("name")) == "aX"',
            RestrictedFormulaRunner::MODE_CONDITION
        );

        $this->assertTrue(true);
    }

    public function testConditionBlocksJourneySignal(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/journey\\\\signal/');

        $this->runner->assertScriptAllowed(
            'journey\\signal("code", "Contact", "id")',
            RestrictedFormulaRunner::MODE_CONDITION
        );
    }

    public function testActionAllowsJourneyUpdateTarget(): void
    {
        $this->runner->assertScriptAllowed(
            'journey\\updateTarget("status", "Converted")',
            RestrictedFormulaRunner::MODE_ACTION
        );

        $this->assertTrue(true);
    }

    public function testActionAllowsJourneySignal(): void
    {
        $this->runner->assertScriptAllowed(
            'journey\\signal("CODE", "Contact", "cid")',
            RestrictedFormulaRunner::MODE_ACTION
        );

        $this->assertTrue(true);
    }

    public function testBlocksBareAttributeWrite(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/setAttribute/');

        $this->runner->assertScriptAllowed(
            'status = "Converted"',
            RestrictedFormulaRunner::MODE_ACTION
        );
    }

    public function testBlocksEntitySetAttribute(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/entity\\\\setAttribute/');

        $this->runner->assertScriptAllowed(
            'entity\\setAttribute("status", "X")',
            RestrictedFormulaRunner::MODE_ACTION
        );
    }

    public function testBlocksEntitySave(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/entity\\\\save/');

        $this->runner->assertScriptAllowed(
            'entity\\save()',
            RestrictedFormulaRunner::MODE_ACTION
        );
    }

    public function testBlocksRecordFindOne(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/record\\\\/');

        $this->runner->assertScriptAllowed(
            'record\\findOne("Contact", "id", "x")',
            RestrictedFormulaRunner::MODE_CONDITION
        );
    }

    public function testBlocksUnknownJourneyFn(): void
    {
        $this->expectException(Error::class);

        $this->runner->assertScriptAllowed(
            'journey\\deleteEverything()',
            RestrictedFormulaRunner::MODE_ACTION
        );
    }

    public function testBlocksExtEmailSend(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/ext\\\\/');

        $this->runner->assertScriptAllowed(
            'ext\\email\\send("x")',
            RestrictedFormulaRunner::MODE_ACTION
        );
    }

    public function testConditionAllowsJsonEncode(): void
    {
        $this->runner->assertScriptAllowed(
            'json\\encode(object\\create())',
            RestrictedFormulaRunner::MODE_CONDITION
        );

        $this->assertTrue(true);
    }

    public function testEmptyScriptIsNoop(): void
    {
        $this->runner->assertScriptAllowed('   ', RestrictedFormulaRunner::MODE_CONDITION);
        $this->assertTrue(true);
    }

    public function testIfThenAllowed(): void
    {
        $this->runner->assertScriptAllowed(
            'ifThen(entity\\attribute("status") == "New", true)',
            RestrictedFormulaRunner::MODE_CONDITION
        );

        $this->assertTrue(true);
    }
}
