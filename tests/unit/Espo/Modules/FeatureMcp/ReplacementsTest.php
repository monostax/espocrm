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

use Espo\Modules\FeatureMcp\Tools\Knowledge\Replacements;
use PHPUnit\Framework\TestCase;

class ReplacementsTest extends TestCase
{
    public function testReplacesInOrderAndKeepsTheRest(): void
    {
        $outcome = Replacements::apply('<p>Open 9h–18h. Price R$ 100.</p>', [
            (object) ['find' => '9h–18h', 'replace' => '8h–20h'],
            (object) ['find' => 'R$ 100', 'replace' => 'R$ 120'],
        ]);

        $this->assertTrue($outcome['ok']);
        $this->assertSame('<p>Open 8h–20h. Price R$ 120.</p>', $outcome['text']);
        $this->assertCount(2, $outcome['changes']);
    }

    public function testAmbiguousNeedsAllOrMoreText(): void
    {
        $outcome = Replacements::apply('a b a', [(object) ['find' => 'a', 'replace' => 'c']]);
        $this->assertFalse($outcome['ok']);
        $this->assertSame('ambiguous', $outcome['problem']);
        $this->assertSame(2, $outcome['occurrences']);

        $all = Replacements::apply('a b a', [(object) ['find' => 'a', 'replace' => 'c', 'all' => true]]);
        $this->assertSame('c b c', $all['text']);
    }

    public function testMissingAndUnchanged(): void
    {
        $this->assertSame('not_found', Replacements::apply('abc', [(object) ['find' => 'x', 'replace' => 'y']])['problem']);
        $this->assertSame('unchanged', Replacements::apply('abc', [(object) ['find' => 'b', 'replace' => 'b']])['problem']);
    }
}
