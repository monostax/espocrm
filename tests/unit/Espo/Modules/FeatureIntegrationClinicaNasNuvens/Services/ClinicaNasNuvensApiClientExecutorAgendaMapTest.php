<?php

namespace tests\unit\Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensApiClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ClinicaNasNuvensApiClientExecutorAgendaMapTest extends TestCase
{
    public function testMapExecutorAgendaPayloadMapsNestedFieldsAndNormalizesValues(): void
    {
        $resolver = $this->createMock(CredentialResolver::class);
        $client = new ClinicaNasNuvensApiClient($resolver);

        $payload = [
            'id' => 50550,
            'idpessoa' => 9298413,
            'nome' => 'CELSO DE SOUZA MATOS & CIA LTDA - LABORATÓRIO CELSO MATOS',
            'ativo' => true,
            'tipoExecutor' => 'PROFISSIONAL',
            'cpfcnpj' => '15.334.758/0001-33',
            'contato' => [
                'telefoneCelular' => null,
                'telefoneComercial' => null,
                'telefoneResidencial' => null,
                'telefoneRecados' => '(93) 99239-2226',
                'email' => '105@com.br',
            ],
            'profissional' => true,
            'profissionalSaude' => [
                'codigo' => '43251',
                'cbo' => null,
                'registroProfissional' => '000',
                'especialidades' => [
                    [
                        'id' => 2266656,
                        'nome' => 'In loco',
                    ],
                ],
                'clinicas' => 'BRUNO MANUEL MOURA DE SOUSA',
            ],
        ];

        $result = $this->invokeMapExecutorAgendaPayload($client, $payload);

        $this->assertSame('50550', $result['profissionalId']);
        $this->assertSame('9298413', $result['idPessoa']);
        $this->assertSame('CELSO DE SOUZA MATOS & CIA LTDA - LABORATÓRIO CELSO MATOS', $result['name']);
        $this->assertSame('PROFISSIONAL', $result['tipoExecutor']);
        $this->assertSame('+5593992392226', $result['telefoneRecados']);
        $this->assertSame('105@com.br', $result['email']);
        $this->assertSame('43251', $result['profissionalCodigo']);
        $this->assertSame('000', $result['registroProfissional']);
        $this->assertSame('In loco', $result['especialidadesTexto']);
        $this->assertSame('2266656', $result['especialidades'][0]['id']);
        $this->assertSame('In loco', $result['especialidades'][0]['nome']);
    }

    public function testMapExecutorAgendaPayloadSupportsDataWrapperAndMissingOptionalFields(): void
    {
        $resolver = $this->createMock(CredentialResolver::class);
        $client = new ClinicaNasNuvensApiClient($resolver);

        $payload = [
            'data' => [
                'id' => '700',
                'tipoExecutor' => 'PROFISSIONAL',
                'nome' => 'Profissional sem contato',
                'profissionalSaude' => [
                    'especialidades' => [],
                ],
            ],
        ];

        $result = $this->invokeMapExecutorAgendaPayload($client, $payload);

        $this->assertSame('700', $result['profissionalId']);
        $this->assertNull($result['idPessoa']);
        $this->assertNull($result['email']);
        $this->assertSame([], $result['especialidades']);
        $this->assertNull($result['especialidadesTexto']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function invokeMapExecutorAgendaPayload(ClinicaNasNuvensApiClient $client, array $payload): array
    {
        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('mapExecutorAgendaPayload');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($client, $payload);

        return $result;
    }
}
