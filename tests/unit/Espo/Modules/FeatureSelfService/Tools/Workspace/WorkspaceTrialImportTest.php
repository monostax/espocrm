<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService\Tools\Workspace;

use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceTrial;
use PHPUnit\Framework\TestCase;

/**
 * When self-service onboarding stops waiting on a connected number's history.
 */
class WorkspaceTrialImportTest extends TestCase
{
    public function testNothingToImportOpensAtOnce(): void
    {
        self::assertTrue(WorkspaceTrial::importSettled([], 0));
    }

    public function testWaitsForTheNewestWindowThenOpens(): void
    {
        self::assertFalse(WorkspaceTrial::importSettled([['status' => 'importing', 'phase' => 0]], 120));
        self::assertTrue(WorkspaceTrial::importSettled([['status' => 'importing', 'phase' => 1]], 120));
        self::assertTrue(WorkspaceTrial::importSettled([['status' => 'importing', 'phase' => 2]], 120));
    }

    public function testAnEndedImportOpens(): void
    {
        foreach (['completed', 'declined', 'failed'] as $status) {
            self::assertTrue(WorkspaceTrial::importSettled([['status' => $status, 'phase' => 0]], 30), $status);
        }
    }

    public function testANumberThatHasNotReportedGetsTenMinutes(): void
    {
        foreach ([null, 'waiting'] as $status) {
            $import = [['status' => $status, 'phase' => null]];

            self::assertFalse(WorkspaceTrial::importSettled($import, WorkspaceTrial::IMPORT_START_WAIT - 1));
            self::assertTrue(WorkspaceTrial::importSettled($import, WorkspaceTrial::IMPORT_START_WAIT));
        }
    }

    public function testEveryNumberMustBeReady(): void
    {
        $imports = [['status' => 'completed', 'phase' => 2], ['status' => 'importing', 'phase' => 0]];

        self::assertFalse(WorkspaceTrial::importSettled($imports, 300));
    }

    public function testNeverWaitsPastTheLimit(): void
    {
        $imports = [['status' => 'importing', 'phase' => 0]];

        self::assertFalse(WorkspaceTrial::importSettled($imports, WorkspaceTrial::IMPORT_MAX_WAIT - 1));
        self::assertTrue(WorkspaceTrial::importSettled($imports, WorkspaceTrial::IMPORT_MAX_WAIT));
    }
}
