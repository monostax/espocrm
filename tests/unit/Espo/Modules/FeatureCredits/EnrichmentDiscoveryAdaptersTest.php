<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Enrichment\Providers\{ApolloDiscovery, CpfCnpjDiscovery, Credentials, RegistroBrDiscovery, RegistroBrDomain, Response, Transport};
use PHPUnit\Framework\TestCase;

class EnrichmentDiscoveryAdaptersTest extends TestCase
{
    public function testUnsupportedDomainAndInvalidCompletionCannotDispatch(): void
    {
        $transport = $this->createMock(Transport::class); $transport->expects($this->never())->method('send');
        $rdap = new RegistroBrDiscovery($transport);
        $this->assertNull($rdap->revision('tenant', 'Account', 'cnpj', ['website' => 'https://example.com']));
        $this->assertSame('failed', $rdap->execute(['product' => 'cnpj', 'kind' => 'page', 'identity' => ['website' => 'https://example.com']], 1000)->state);
        $credentials = $this->createStub(Credentials::class); $credentials->method('get')->willReturn(['key' => 'fixture']);
        $apollo = new ApolloDiscovery($credentials, $transport);
        $this->assertSame('failed', $apollo->execute(['product' => 'employees', 'kind' => 'completion',
            'identity' => ['linkedinUrl' => 'https://linkedin.com/company/example'],
            'input' => ['completionToken' => '{}', 'candidateKey' => str_repeat('a', 64)]], 1000)->state);
    }

    public function testRdapHttpFailureCannotCreatePurchasedValues(): void
    {
        foreach ([0 => 'unknown', 500 => 'unknown', 404 => 'no_match', 429 => 'failed', 403 => 'failed', 200 => 'unknown'] as $status => $state) {
            $transport = $this->createMock(Transport::class);
            $transport->expects($this->once())->method('send')->willReturn(new Response($status, null));
            $result = (new RegistroBrDiscovery($transport))->execute(['product' => 'cnpj', 'kind' => 'page', 'identity' => ['website' => 'registro.br']], 1000);
            $this->assertSame($state, $result->state); $this->assertSame([], $result->items);
        }
    }

    public function testCpfWrongCompanyOrPackageCannotAcquirePeople(): void
    {
        $credentials = $this->createStub(Credentials::class); $credentials->method('get')->willReturn(['token' => 'fixture']);
        foreach ([['cnpj' => '04252011000110', 'pacoteUsado' => 6], ['cnpj' => '11222333000181', 'pacoteUsado' => 19]] as $data) {
            $transport = $this->createMock(Transport::class);
            $transport->expects($this->once())->method('send')->willReturn(new Response(200, ['status' => 1, 'socios' => [], ...$data]));
            $result = (new CpfCnpjDiscovery($credentials, $transport))->execute(['product' => 'socios', 'kind' => 'page', 'identity' => ['cnpj' => '11222333000181']], 1000);
            $this->assertSame('unknown', $result->state); $this->assertSame([], $result->items);
        }
    }

    public function testApolloCompletionRejectsDifferentPersonOrFormerEmployment(): void
    {
        $organization = str_repeat('a', 24); $id = str_repeat('b', 24);
        $profile = 'https://linkedin.com/company/example';
        $person = ['id' => $id, 'name' => 'Person', 'linkedin_url' => 'https://linkedin.com/in/person',
            'organization' => ['id' => $organization, 'linkedin_url' => $profile],
            'employment_history' => [['organization_id' => $organization, 'current' => false, 'end_date' => '2020-01-01']]];
        $credentials = $this->createStub(Credentials::class); $credentials->method('get')->willReturn(['key' => 'fixture']);
        foreach ([[$person, 'no_match'], [[...$person, 'id' => str_repeat('c', 24)], 'unknown']] as [$value, $state]) {
            $transport = $this->createMock(Transport::class);
            $transport->expects($this->once())->method('send')->willReturn(new Response(200, ['person' => $value]));
            $result = (new ApolloDiscovery($credentials, $transport))->execute(['product' => 'employees', 'kind' => 'completion',
                'identity' => ['linkedinUrl' => $profile], 'input' => [
                    'candidateKey' => \Espo\Modules\FeatureCredits\Enrichment\Quotes::hash(['apollo', $id]),
                    'completionToken' => json_encode(['person' => $id, 'organization' => $organization])]], 1000);
            $this->assertSame($state, $result->state); $this->assertSame([], $result->items);
        }
    }

    public function testRegistrableDomainsAndUnsupportedInputs(): void
    {
        foreach ([
            'HTTPS://WWW.Example.COM.BR./path?q=1#anchor' => 'example.com.br',
            'https://mail.registro.br' => 'registro.br',
            'example.com.br:443/path' => 'example.com.br',
            'https://www.secretaria.sp.gov.br' => 'secretaria.sp.gov.br',
            'https://www.person.family.nom.br' => 'person.family.nom.br',
            'https://www.xn--caf-dma.com.br' => 'xn--caf-dma.com.br',
            'https://www.café.com.br' => 'xn--caf-dma.com.br',
        ] as $input => $expected) $this->assertSame($expected, RegistroBrDomain::normalize($input));
        foreach (['com.br', 'br', 'sp.gov.br', 'family.nom.br', 'https://example.com', 'https://127.0.0.1',
            'https://[::1]', 'https://user:password@example.com.br', '//example.com.br', 'ftp://example.com.br',
            'https://example.com.br.evil.test', 'https://bad_label.com.br', 'https://-bad.com.br', "https://bad\\name.com.br",
            "https://example.com.br/a\nb", 'https://example..com.br'] as $input) {
            $this->assertNull(RegistroBrDomain::normalize($input), $input);
        }
    }

    public function testRdapOnlyRevealsExactDomainRegistrantValidCnpj(): void
    {
        $registrant = ['roles' => ['registrant'], 'publicIds' => [['type' => 'cnpj', 'identifier' => '05.506.560/0001-36']]];
        $base = ['objectClassName' => 'domain', 'ldhName' => 'registro.br', 'entities' => [$registrant]];
        foreach ([
            [$base, 'succeeded'],
            [[...$base, 'ldhName' => 'other.br'], 'unknown'],
            [[...$base, 'entities' => [[...$registrant, 'roles' => ['technical']]]], 'no_match'],
            [[...$base, 'entities' => [[...$registrant, 'publicIds' => [['type' => 'cpf', 'identifier' => '52998224725']]]]], 'no_match'],
            [[...$base, 'entities' => [[...$registrant, 'publicIds' => [['type' => 'cnpj', 'identifier' => '**.506.560/0001-36']]]]], 'no_match'],
            [[...$base, 'entities' => [$registrant, [...$registrant, 'publicIds' => [['type' => 'cnpj', 'identifier' => '11222333000181']]]]], 'unknown'],
        ] as [$data, $state]) {
            $transport = $this->createMock(Transport::class);
            $transport->expects($this->once())->method('send')->with('registrobr', 'GET', '/domain/registro.br', [], [], 1234)->willReturn(new Response(200, $data));
            $adapter = new RegistroBrDiscovery($transport);
            $result = $adapter->execute(['product' => 'cnpj', 'kind' => 'page', 'identity' => ['website' => 'https://www.registro.br/path']], 1234);
            $this->assertSame($state, $result->state);
            if ($state === 'succeeded') $this->assertSame(['cnpj' => '05506560000136'], $result->items[0]['values']);
            else $this->assertSame([], $result->items);
        }
    }

    public function testCpfPackageSixUsesOwnCompanyAndNeverRepresentativeOrCompanyContacts(): void
    {
        $credentials = $this->createStub(Credentials::class); $credentials->method('get')->willReturn(['token' => 'fixture']);
        $person = ['tipo' => 'Pessoa Física', 'cpf_cnpj_socio' => '529.982.247-25', 'nome' => 'Person',
            'qualificacao_socio' => ['id' => 49, 'descricao' => 'Sócio-Administrador '], 'data_entrada' => '2020-01-01',
            'cpf_representante_legal' => '11144477735', 'email' => 'hidden@example.test'];
        $data = ['status' => 1, 'pacoteUsado' => 6, 'cnpj' => '11.222.333/0001-81', 'email' => 'company@example.test',
            'socios' => [$person, [...$person, 'tipo' => 'Pessoa Jurídica'], [...$person, 'cpf_cnpj_socio' => '***.982.247-**']]];
        $transport = $this->createMock(Transport::class);
        $transport->expects($this->once())->method('send')->with('cpfcnpj', 'GET', '/fixture/6/11222333000181', [], [], 1000)->willReturn(new Response(200, $data));
        $result = (new CpfCnpjDiscovery($credentials, $transport))->execute(['product' => 'socios', 'kind' => 'page', 'identity' => ['cnpj' => '11222333000181']], 1000);
        $this->assertSame('succeeded', $result->state); $this->assertCount(1, $result->items);
        $this->assertSame(['cpf' => '52998224725', 'name' => 'Person', 'role' => 'Sócio-Administrador', 'since' => '2020-01-01'], $result->items[0]['values']);
        $this->assertArrayNotHasKey('incidental', $result->items[0]);
    }

    public function testApolloResolutionSearchAndDemographicCompletionAreSeparateBoundRequests(): void
    {
        $org = ['id' => str_repeat('a', 24), 'linkedin_url' => 'https://linkedin.com/company/example'];
        $person = ['id' => str_repeat('b', 24), 'name' => 'Person', 'linkedin_url' => 'https://linkedin.com/in/person',
            'organization' => $org, 'employment_history' => [['organization_id' => $org['id'], 'current' => true, 'end_date' => null]],
            'title' => 'CEO', 'email' => 'excluded@example.test'];
        $credentials = $this->createStub(Credentials::class); $credentials->method('get')->willReturn(['key' => 'fixture']);
        $calls = [];
        $responses = [new Response(200, ['organization' => $org]), new Response(200, ['people' => [['id' => $person['id'], 'last_name_obfuscated' => 'X***']], 'total_entries' => 1]), new Response(200, ['person' => $person])];
        $transport = $this->createMock(Transport::class);
        $transport->expects($this->exactly(3))->method('send')->willReturnCallback(function (...$args) use (&$calls, &$responses) { $calls[] = $args; return array_shift($responses); });
        $adapter = new ApolloDiscovery($credentials, $transport);
        $request = ['product' => 'employees', 'kind' => 'page', 'identity' => ['linkedinUrl' => $org['linkedin_url']],
            'filters' => ['titles' => ['CEO', 'Marketing Manager'], 'jobLevels' => ['CXO', 'SVP', 'VP', 'Senior Manager', 'Manager'],
                'seniorities' => ['founder']]];
        $resolved = $adapter->execute($request, 1000);
        $this->assertSame([], $resolved->items); $this->assertNotNull($resolved->nextCursor);
        $page = $adapter->execute([...$request, 'input' => ['cursor' => $resolved->nextCursor]], 1000);
        $this->assertArrayHasKey('completionToken', $page->items[0]);
        $result = $adapter->execute([...$request, 'kind' => 'completion', 'input' => $page->items[0]], 1000);
        $this->assertSame('succeeded', $result->state); $this->assertSame($page->items[0]['candidateKey'], $result->items[0]['candidateKey']);
        $this->assertSame('https://www.linkedin.com/in/person', $result->items[0]['values']['linkedinUrl']);
        $this->assertArrayNotHasKey('email', $result->items[0]['values']);
        $this->assertSame([$org['id']], $calls[1][3]['organization_ids']);
        $this->assertSame(['CEO', 'Marketing Manager'], $calls[1][3]['person_titles']);
        $this->assertSame(['founder', 'c_suite', 'vp', 'manager'], $calls[1][3]['person_seniorities']);
        $this->assertSame('true', $calls[1][3]['include_similar_titles']);
        $this->assertArrayNotHasKey('jobLevels', $calls[1][3]);
        foreach (['reveal_personal_emails', 'reveal_phone_number', 'run_waterfall_email', 'run_waterfall_phone'] as $flag) $this->assertSame('false', $calls[2][3][$flag]);
    }
}
