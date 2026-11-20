<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\DealTargets;
use Espo\Modules\FeatureCatchUp\Services\Parties;
use Espo\Modules\FeatureCatchUp\Services\Records;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use PHPUnit\Framework\TestCase;

class SummaryTest extends TestCase
{
    /** A link from Avisos puts a card further down the deck first; one someone acted on must not come back as a card. */
    public function testAStoredCardSomeoneActedOnIsNothingToShowNotTheCardAgain(): void
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn([
            'verdict' => 'customer_waiting', 'reason' => 'Asked for the price.', 'card' => ['headline' => 'Enviar o preço'], 'resolved' => true,
        ]);
        $verdicts->expects(self::never())->method('store');
        $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([$this->createMock(User::class), $verdicts,
            $this->createMock(Parties::class), $this->createMock(Records::class), $this->createMock(DealTargets::class)])
            ->onlyMethods(['request'])->getMock();
        $summary->expects(self::never())->method('request');

        $answer = $summary->analyse(['type' => 'conversation', 'id' => 'c1', 'revision' => 'r1', 'evidenceScope' => 's', 'tenantId' => 't'], 'pt-BR');

        self::assertSame('completed', $answer['status']);
        self::assertSame('none', $answer['verdict']);
        self::assertNull($answer['card']);
    }
}
