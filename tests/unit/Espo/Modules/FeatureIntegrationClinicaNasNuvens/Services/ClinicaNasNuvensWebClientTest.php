<?php

namespace tests\unit\Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckManager;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensWebClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class ClinicaNasNuvensWebClientTest extends TestCase
{
    public function testParserMapsSelectorsAndNormalizesValues(): void
    {
        $fixtureHtml = $this->getFixtureHtml();

        $resolver = $this->createMock(CredentialResolver::class);
        $healthCheckManager = $this->createMock(HealthCheckManager::class);
        $entityManager = $this->createMock(EntityManager::class);

        $client = new TestableClinicaNasNuvensWebClient($resolver, $healthCheckManager, $entityManager);

        $payload = $client->parsePublic($fixtureHtml, 'DET-123');

        $this->assertSame('DET-123', $payload['faturamentoId']);
        $this->assertSame('DOC-2026-0001', $payload['documento']);
        $this->assertSame('2026-03-25', $payload['dataFaturamento']);
        $this->assertSame('Dr. João Silva', $payload['profissionalNome']);
        $this->assertSame('Conta Particular', $payload['conta']);
        $this->assertSame(146.00, $payload['valor']);
        $this->assertSame('BRL', $payload['valorCurrency']);
        $this->assertSame('1/3', $payload['parcela']);
        $this->assertSame('2026-04-05', $payload['dataVencimento']);
        $this->assertSame('Consulta de retorno & ajuste', $payload['description']);
        $this->assertSame('121148375', $payload['agendamentoId']);
    }

    public function testInvalidSessionTriggersRefreshAndSingleRetry(): void
    {
        $fixtureHtml = $this->getFixtureHtml();

        $resolver = $this->createMock(CredentialResolver::class);
        $resolver->method('resolve')
            ->willReturn((object) ['sessionCookies' => 'cookie=initial']);

        $healthCheckManager = $this->createMock(HealthCheckManager::class);
        $entityManager = $this->createMock(EntityManager::class);

        $credential = $this->createMock(Entity::class);
        $credential->method('getId')->willReturn('cred-web-1');
        $credential->method('get')->willReturnMap([
            ['config', null],
        ]);

        $client = new TestableClinicaNasNuvensWebClient($resolver, $healthCheckManager, $entityManager);
        $client->setRequestResponses([
            [
                'status' => 200,
                'body' => '<html><body><input name="password"></body></html>',
                'message' => 'ok',
            ],
            [
                'status' => 200,
                'body' => $fixtureHtml,
                'message' => 'ok',
            ],
        ]);
        $client->setRefreshCookies('cookie=refreshed');

        $payload = $client->getDetalhesConta($credential, 'DET-RETRY');

        $this->assertSame('DET-RETRY', $payload['faturamentoId']);
        $this->assertSame(2, $client->getRequestCallCount());
        $this->assertSame(1, $client->getRefreshCallCount());
    }

    public function testNonTargetHtmlFailsWithoutRefresh(): void
    {
        $resolver = $this->createMock(CredentialResolver::class);
        $resolver->method('resolve')
            ->willReturn((object) ['sessionCookies' => 'cookie=initial']);

        $healthCheckManager = $this->createMock(HealthCheckManager::class);
        $entityManager = $this->createMock(EntityManager::class);

        $credential = $this->createMock(Entity::class);
        $credential->method('getId')->willReturn('cred-web-2');
        $credential->method('get')->willReturnMap([
            ['config', null],
        ]);

        $client = new TestableClinicaNasNuvensWebClient($resolver, $healthCheckManager, $entityManager);
        $client->setRequestResponses([
            [
                'status' => 200,
                'body' => '<html><body><h1>Erro genérico</h1></body></html>',
                'message' => 'ok',
            ],
        ]);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('non-target-html');

        $client->getDetalhesConta($credential, 'DET-FAIL');
    }

    public function testResumoAgendaFinanceiroParserMapsCanceledStatusFromAlert(): void
    {
        $resolver = $this->createMock(CredentialResolver::class);
        $healthCheckManager = $this->createMock(HealthCheckManager::class);
        $entityManager = $this->createMock(EntityManager::class);

        $client = new TestableClinicaNasNuvensWebClient($resolver, $healthCheckManager, $entityManager);

        $snapshot = $client->parseResumoSnapshotPublic(
            '<html><body><div id="tab_financeiro"><div><div><table><tbody><tr><td><div><div><h1>Esse faturamento foi descartado!</h1></div></div></td></tr></tbody></table></div></div></div></body></html>'
        );

        $this->assertSame([], $snapshot['faturamentoIdList']);
        $this->assertSame('CANCELADO', $snapshot['statusFaturamento']);
    }

    public function testResumoAgendaFinanceiroParserMapsSemRegistroStatusFromAlert(): void
    {
        $resolver = $this->createMock(CredentialResolver::class);
        $healthCheckManager = $this->createMock(HealthCheckManager::class);
        $entityManager = $this->createMock(EntityManager::class);

        $client = new TestableClinicaNasNuvensWebClient($resolver, $healthCheckManager, $entityManager);

        $snapshot = $client->parseResumoSnapshotPublic(
            '<html><body><div id="tab_financeiro"><div><div><table><tbody><tr><td><div><div><h1>Nenhum resultado encontrado</h1></div></div></td></tr></tbody></table></div></div></div></body></html>'
        );

        $this->assertSame([], $snapshot['faturamentoIdList']);
        $this->assertSame('SEM_REGISTRO', $snapshot['statusFaturamento']);
    }

    public function testResumoAgendaFinanceiroParserMapsFaturadoStatusWhenIdsExist(): void
    {
        $resolver = $this->createMock(CredentialResolver::class);
        $healthCheckManager = $this->createMock(HealthCheckManager::class);
        $entityManager = $this->createMock(EntityManager::class);

        $client = new TestableClinicaNasNuvensWebClient($resolver, $healthCheckManager, $entityManager);

        $snapshot = $client->parseResumoSnapshotPublic(
            '<html><body><div id="tab_financeiro"><div><div><table><tbody><tr>' .
            '<td></td><td></td><td></td><td></td><td></td><td></td>' .
            '<td><div><a data-faturamento="fat-1"></a><a data-faturamento="fat-2"></a></div></td>' .
            '</tr></tbody></table></div></div></div></body></html>'
        );

        $this->assertSame(['fat-1', 'fat-2'], $snapshot['faturamentoIdList']);
        $this->assertSame('FATURADO', $snapshot['statusFaturamento']);
    }

    private function getFixtureHtml(): string
    {
        $path = dirname(__DIR__, 4) .
            '/testData/FeatureIntegrationClinicaNasNuvens/ClinicaNasNuvensFaturamentoDetalhesConta.html';

        return (string) file_get_contents($path);
    }
}

class TestableClinicaNasNuvensWebClient extends ClinicaNasNuvensWebClient
{
    /** @var array<int, array{status:int,body:string,message:string}> */
    private array $requestResponses = [];
    private ?string $refreshCookies = null;
    private int $requestCallCount = 0;
    private int $refreshCallCount = 0;

    /**
     * @param array<int, array{status:int,body:string,message:string}> $responses
     */
    public function setRequestResponses(array $responses): void
    {
        $this->requestResponses = $responses;
    }

    public function setRefreshCookies(?string $cookies): void
    {
        $this->refreshCookies = $cookies;
    }

    public function getRequestCallCount(): int
    {
        return $this->requestCallCount;
    }

    public function getRefreshCallCount(): int
    {
        return $this->refreshCallCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function parsePublic(string $html, string $faturamentoId): array
    {
        return $this->parseDetalhesContaHtml($html, $faturamentoId);
    }

    /**
     * @return array{faturamentoIdList: string[], statusFaturamento: ?string}
     */
    public function parseResumoSnapshotPublic(string $html): array
    {
        return $this->parseResumoAgendaFinanceiroSnapshotHtml($html);
    }

    protected function requestDetailsPage(string $url, string $cookies): array
    {
        $this->requestCallCount++;

        if ($this->requestResponses === []) {
            return ['status' => 500, 'body' => '', 'message' => 'no-response'];
        }

        return array_shift($this->requestResponses);
    }

    protected function refreshCredentialSessionCookies(Entity $credential): ?string
    {
        $this->refreshCallCount++;

        return $this->refreshCookies;
    }
}
