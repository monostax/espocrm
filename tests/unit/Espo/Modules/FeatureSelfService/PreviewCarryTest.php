<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService;

use Espo\Modules\FeatureSelfService\Tools\Provisioning\Preview\PreviewCarry;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Preview\PreviewClient;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\WorkspaceStarter;
use PHPUnit\Framework\TestCase;

class PreviewCarryTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function carry(array $override = []): array
    {
        return array_replace_recursive([
            'version' => 1,
            'companyName' => 'Linea Planejados',
            'agent' => [
                'name' => 'Camila',
                'instructions' => "Você é Camila, da Linea Planejados.\nResponda curto.",
                'knowledge' => [
                    ['title' => 'Sobre a empresa', 'body' => "Móveis planejados em Campinas.\nProjeto 3D."],
                ],
            ],
            'funnel' => [
                'stages' => [
                    ['name' => 'Contato inicial', 'description' => 'Primeira mensagem.', 'kind' => 'open'],
                    ['name' => 'Projeto 3D', 'description' => 'Projeto em andamento.', 'kind' => 'open'],
                    ['name' => 'Proposta', 'description' => 'Proposta enviada.', 'kind' => 'open'],
                    ['name' => 'Contrato assinado', 'description' => 'Fechou.', 'kind' => 'won'],
                    ['name' => 'Não fechado', 'description' => 'Desistiu.', 'kind' => 'lost'],
                ],
            ],
        ], $override);
    }

    public function testReadsTheSiteContract(): void
    {
        $carry = PreviewCarry::fromArray($this->carry());

        self::assertNotNull($carry);
        self::assertSame('Linea Planejados', $carry->companyName);
        self::assertSame("Você é Camila, da Linea Planejados.\nResponda curto.", $carry->instructions);
        self::assertSame([['title' => 'Sobre a empresa', 'body' => "Móveis planejados em Campinas.\nProjeto 3D."]], $carry->knowledge);
        self::assertSame(['Contato inicial', 'Projeto 3D', 'Proposta'], array_column($carry->openStages, 'name'));
        self::assertSame('Contrato assinado', $carry->won['name']);
        self::assertSame('Não fechado', $carry->lost['name']);
    }

    public function testRoundTripsThroughWhatProvisioningStores(): void
    {
        $carry = PreviewCarry::fromArray($this->carry());
        $again = PreviewCarry::fromArray(json_decode((string) json_encode($carry?->toArray()), true));

        self::assertEquals($carry, $again);
    }

    public function testRefusesOtherVersionsAndTheUnavailableMarker(): void
    {
        self::assertNull(PreviewCarry::fromArray($this->carry(['version' => 2])));
        self::assertNull(PreviewCarry::fromArray(['unavailable' => true]));
        self::assertNull(PreviewCarry::fromArray(['version' => 1, 'agent' => ['instructions' => ''], 'funnel' => ['stages' => []]]));
    }

    public function testClampsAndDropsWhatItCannotUse(): void
    {
        $data = $this->carry();
        $data['companyName'] = str_repeat('a', 300);
        $data['agent']['instructions'] = "Linha 1\u{0007}\nLinha 2";
        $data['funnel']['stages'] = [
            ['name' => 'Novo', 'description' => 'a', 'kind' => 'open'],
            ['name' => 'novo', 'description' => 'duplicate', 'kind' => 'open'],
            ['name' => '<b>x</b>', 'description' => 'markup', 'kind' => 'open'],
            ['name' => '', 'description' => 'empty', 'kind' => 'open'],
            ['name' => 'Vendido', 'description' => 'b', 'kind' => 'won'],
            ['name' => 'Vendido de novo', 'description' => 'second won', 'kind' => 'won'],
            ['name' => 'Estranho', 'description' => 'c', 'kind' => 'weird'],
            ...array_map(fn (int $i) => ['name' => "Etapa {$i}", 'description' => '', 'kind' => 'open'], range(1, 9)),
        ];

        $carry = PreviewCarry::fromArray($data);

        self::assertNotNull($carry);
        self::assertSame(100, mb_strlen($carry->companyName));
        self::assertSame("Linha 1\nLinha 2", $carry->instructions);
        self::assertSame(['Novo', 'Etapa 1', 'Etapa 2', 'Etapa 3', 'Etapa 4', 'Etapa 5'], array_column($carry->openStages, 'name'));
        self::assertSame('Vendido', $carry->won['name']);
        self::assertSame('Perdido', $carry->lost['name'], 'a missing lost stage falls back to the default name');
    }

    public function testPreviewStagesKeepTheDefaultRuleForGanhoAndPerdido(): void
    {
        $carry = PreviewCarry::fromArray($this->carry());
        self::assertNotNull($carry);

        $stages = WorkspaceStarter::previewStages($carry);

        self::assertSame(
            [['Contato inicial', 1, 10], ['Projeto 3D', 2, 50], ['Proposta', 3, 90], ['Contrato assinado', 4, 100], ['Não fechado', 5, 0]],
            array_map(fn (array $stage) => [$stage['name'], $stage['order'], $stage['probability']], $stages),
        );
        self::assertSame('Primeira mensagem.', $stages[0]['description']);
        self::assertStringStartsWith('A empresa confirmou o agendamento ou a compra', $stages[3]['description']);
        self::assertStringStartsWith('O cliente recusou explicitamente', $stages[4]['description']);
        self::assertStringNotContainsString('Estacionada', $stages[4]['description']);
        self::assertSame(['success', 'danger'], [$stages[3]['style'], $stages[4]['style']]);
    }

    public function testASingleOpenStageSitsAtHalfway(): void
    {
        $data = $this->carry();
        $data['funnel']['stages'] = [['name' => 'Conversa', 'description' => '', 'kind' => 'open']];
        $carry = PreviewCarry::fromArray($data);
        self::assertNotNull($carry);

        self::assertSame([50, 100, 0], array_column(WorkspaceStarter::previewStages($carry), 'probability'));
    }

    public function testPreviewIdsAreTheSitesTwentyCharacterIds(): void
    {
        self::assertTrue(PreviewClient::isPreviewId('gwd3e64sak3pvqyxzru9'));
        self::assertFalse(PreviewClient::isPreviewId('linte.com'));
        self::assertFalse(PreviewClient::isPreviewId('GWD3E64SAK3PVQYXZRU9'));
        self::assertFalse(PreviewClient::isPreviewId(null));
    }
}
