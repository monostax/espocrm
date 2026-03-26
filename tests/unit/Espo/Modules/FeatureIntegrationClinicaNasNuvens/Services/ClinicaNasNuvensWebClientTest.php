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

    public function testProcedimentoConvenioParserNormalizesRows(): void
    {
        $resolver = $this->createMock(CredentialResolver::class);
        $healthCheckManager = $this->createMock(HealthCheckManager::class);
        $entityManager = $this->createMock(EntityManager::class);

        $client = new TestableClinicaNasNuvensWebClient($resolver, $healthCheckManager, $entityManager);
        $rows = $client->parseProcedimentoConvenioPricingPublic($this->getProcedimentoFixtureHtml());

        $this->assertCount(3, $rows);

        $this->assertSame('proc-conv-1001', $rows[0]['codigoTipoProcedimentoConvenio']);
        $this->assertSame('convenio-1', $rows[0]['codigoTipoConvenio']);
        $this->assertTrue($rows[0]['isActive']);
        $this->assertSame(150.25, $rows[0]['precoPaciente']);
        $this->assertSame(125.00, $rows[0]['precoConvenio']);

        $this->assertNull($rows[1]['codigoTipoProcedimentoConvenio']);
        $this->assertSame('convenio-2', $rows[1]['codigoTipoConvenio']);
        $this->assertFalse($rows[1]['isActive']);
        $this->assertSame(0.00, $rows[1]['precoPaciente']);
        $this->assertSame(0.00, $rows[1]['precoConvenio']);

        $this->assertSame('convenio-3', $rows[2]['codigoTipoConvenio']);
        $this->assertTrue($rows[2]['isActive']);
        $this->assertNull($rows[2]['precoPaciente']);
        $this->assertSame(89.50, $rows[2]['precoConvenio']);
    }

    public function testProcedimentoConvenioParserReturnsEmptyListWhenNoRows(): void
    {
        $resolver = $this->createMock(CredentialResolver::class);
        $healthCheckManager = $this->createMock(HealthCheckManager::class);
        $entityManager = $this->createMock(EntityManager::class);

        $client = new TestableClinicaNasNuvensWebClient($resolver, $healthCheckManager, $entityManager);
        $rows = $client->parseProcedimentoConvenioPricingPublic('<html><body><table id="convenios"><tbody></tbody></table></body></html>');

        $this->assertSame([], $rows);
    }

    public function testProcedimentoConvenioParserHandlesNestedInputsAndDotDecimalFormat(): void
    {
        $resolver = $this->createMock(CredentialResolver::class);
        $healthCheckManager = $this->createMock(HealthCheckManager::class);
        $entityManager = $this->createMock(EntityManager::class);

        $client = new TestableClinicaNasNuvensWebClient($resolver, $healthCheckManager, $entityManager);

        $html = '<html><body><table id="convenios"><tbody>' .
            '<tr>' .
            '<td><input type="checkbox" name="precificacao[6].ativo" checked="checked"></td>' .
            '<td><input type="hidden" name="precificacao[6].codigoTipoProcedimentoConvenio" value="11975368">' .
            '<input type="hidden" name="precificacao[6].codigoTipoConvenio" value="56552">COOFLONA</td>' .
            '<td><div class="input-group"><input type="text" name="precificacao[6].valorPaciente" value="0.00"></div></td>' .
            '<td><div class="input-group"><input type="text" name="precificacao[6].valorConvenio" value="950.00"></div></td>' .
            '</tr>' .
            '<tr>' .
            '<td><input type="checkbox" name="precificacao[8].ativo" checked="checked"></td>' .
            '<td><input type="hidden" name="precificacao[8].codigoTipoProcedimentoConvenio" value="10802577">' .
            '<input type="hidden" name="precificacao[8].codigoTipoConvenio" value="57565">ECO MAIS</td>' .
            '<td><div class="input-group"><input type="text" name="precificacao[8].valorPaciente" value="451.20"></div></td>' .
            '<td><div class="input-group"><input type="text" name="precificacao[8].valorConvenio" value="0.00"></div></td>' .
            '</tr>' .
            '</tbody></table></body></html>';

        $rows = $client->parseProcedimentoConvenioPricingPublic($html);

        $this->assertCount(2, $rows);
        $this->assertSame('56552', $rows[0]['codigoTipoConvenio']);
        $this->assertTrue($rows[0]['isActive']);
        $this->assertSame(0.00, $rows[0]['precoPaciente']);
        $this->assertSame(950.00, $rows[0]['precoConvenio']);
        $this->assertSame(451.20, $rows[1]['precoPaciente']);
    }

    public function testValidateProcedimentoHtmlRequiresConveniosTable(): void
    {
        $resolver = $this->createMock(CredentialResolver::class);
        $healthCheckManager = $this->createMock(HealthCheckManager::class);
        $entityManager = $this->createMock(EntityManager::class);

        $client = new TestableClinicaNasNuvensWebClient($resolver, $healthCheckManager, $entityManager);

        $valid = $client->validateProcedimentoHtmlPublic(200, '<html><body><table id="convenios"></table></body></html>');
        $invalid = $client->validateProcedimentoHtmlPublic(200, '<html><body><h1>Sem tabela</h1></body></html>');
        $authLike = $client->validateProcedimentoHtmlPublic(200, '<html><body><input name="password"></body></html>');

        $this->assertTrue($valid['valid']);
        $this->assertFalse($invalid['valid']);
        $this->assertSame('non-target-html', $invalid['reason']);
        $this->assertFalse($authLike['valid']);
        $this->assertTrue($authLike['authLike']);
        $this->assertSame('login-page-detected', $authLike['reason']);
    }

    public function testProcedimentoPricingRetriesOnceAfterAuthLikeHtml(): void
    {
        $resolver = $this->createMock(CredentialResolver::class);
        $resolver->method('resolve')
            ->willReturn((object) ['sessionCookies' => 'cookie=initial']);

        $healthCheckManager = $this->createMock(HealthCheckManager::class);
        $entityManager = $this->createMock(EntityManager::class);

        $credential = $this->createMock(Entity::class);
        $credential->method('getId')->willReturn('cred-web-proc-1');
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
                'body' => $this->getProcedimentoFixtureHtml(),
                'message' => 'ok',
            ],
        ]);
        $client->setRefreshCookies('cookie=refreshed');

        $payload = $client->getProcedimentoConvenioPricingByProcedimentoId($credential, 'proc-100');

        $this->assertSame(2, $client->getRequestCallCount());
        $this->assertSame(1, $client->getRefreshCallCount());
        $this->assertCount(3, $payload['rows']);
        $this->assertSame(2, $payload['telemetry']['attempts']);
    }

    private function getFixtureHtml(): string
    {
        $path = dirname(__DIR__, 4) .
            '/testData/FeatureIntegrationClinicaNasNuvens/ClinicaNasNuvensFaturamentoDetalhesConta.html';

        return (string) file_get_contents($path);
    }

    private function getProcedimentoFixtureHtml(): string
    {
        $path = dirname(__DIR__, 4) .
            '/testData/FeatureIntegrationClinicaNasNuvens/ClinicaNasNuvensProcedimentoConvenios.html';

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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseProcedimentoConvenioPricingPublic(string $html): array
    {
        return $this->parseProcedimentoConvenioPricingHtml($html);
    }

    /**
     * @return array{valid:bool, authLike:bool, reason:string}
     */
    public function validateProcedimentoHtmlPublic(int $status, string $html): array
    {
        return $this->validateProcedimentoHtml($status, $html);
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
