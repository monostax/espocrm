<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Enrichment\Providers\{Apollo,Credentials,CpfCnpj,Hub,Response,Transport};
use PHPUnit\Framework\TestCase;

class EnrichmentSuppliersTest extends TestCase
{
    private array $calls = [];
    private array $responses = [];
    private array $secrets = ['token' => 'fixture-token', 'contract' => 'fixture-contract', 'key' => 'fixture-key'];

    private function supplier(string $class): Apollo|CpfCnpj|Hub
    {
        $credentials = $this->createStub(Credentials::class);
        $credentials->method('get')->willReturnCallback(fn () => $this->secrets);
        $transport = $this->createStub(Transport::class);
        $transport->method('send')->willReturnCallback(function (...$args): Response {
            $this->calls[] = $args;
            return array_shift($this->responses) ?? new Response(0,null);
        });
        return new $class($credentials,$transport);
    }

    public function testEligibilityHasNoIoAndCredentialRotationChangesRevision(): void
    {
        $cpf = $this->supplier(CpfCnpj::class); $hub = $this->supplier(Hub::class); $apollo = $this->supplier(Apollo::class);
        $identity = ['cpf' => '52998224725'];
        $revision = $cpf->revision('tenant','Contact','phone',$identity);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$revision);
        $this->assertNull($cpf->revision('tenant','Contact','phone',['cnpj' => '11222333000181']));
        $this->assertNull($cpf->revision('tenant','Account','company',['cnpj' => '11222333000181']));
        $this->assertNull($hub->revision('tenant','Account','email',['cnpj' => '12ABC34501DE35']));
        $this->assertNull($apollo->revision('tenant','Account','email',['linkedinUrl' => 'https://www.linkedin.com/company/example']));
        $this->assertNotNull($apollo->revision('tenant','Account','phone',['linkedinUrl' => 'https://www.linkedin.com/company/example']));
        $this->assertNull($apollo->revision('tenant','Account','phone',['website' => 'https://example.test']));
        $this->assertNull($apollo->revision('tenant','Contact','email',['emailAddress' => 'person@example.test']));
        $this->assertNull($apollo->revision('tenant','Contact','email',[]));
        $this->secrets['token'] = 'rotated-fixture';
        $this->assertNotSame($revision,$cpf->revision('tenant','Contact','phone',$identity));
        $this->secrets = [];
        $this->assertNull($cpf->revision('tenant','Contact','phone',$identity));
        $this->assertSame('failed',$cpf->lookup('Contact','phone',$identity)->state);
        $this->assertSame([],$this->calls);
    }

    public function testCpfCnpjPackagesNormalizeOnlyRequestedOwnValues(): void
    {
        $supplier = $this->supplier(CpfCnpj::class);
        $this->responses[] = new Response(200,['status' => 1,'cpf' => '529.982.247-25','pacoteUsado' => 21,
            'emails' => ['a@example.test','bad***@example.test','a@example.test'], 'telefones' => ['11987654321']]);
        $result = $supplier->lookup('Contact','email',['cpf' => '52998224725']);
        $this->assertSame(['a@example.test'],$result->values);
        $this->assertSame(['phone' => ['+5511987654321']],$result->hidden);
        $this->assertSame('/fixture-token/21/52998224725',$this->calls[0][2]);
        $this->responses[] = new Response(200,['status' => 1,'cnpj' => '11.222.333/0001-81','pacoteUsado' => 6,
            'email' => 'company@example.test','telefones' => [['ddd' => '11','numero' => '23456789']],
            'socios' => [['email' => 'owner@example.test','phone' => '11999999999']]]);
        $result = $supplier->lookup('Account','phone',['cnpj' => '11222333000181']);
        $this->assertSame(['+551123456789'],$result->values);
        $this->assertSame(['email' => ['company@example.test']],$result->hidden);
        $this->assertSame('/fixture-token/6/11222333000181',$this->calls[1][2]);
        $this->responses[] = new Response(200,['status' => 1,'cpf' => '01234567890','pacoteUsado' => 21,'emails' => ['wrong@example.test']]);
        $this->assertSame('ambiguous',$supplier->lookup('Contact','email',['cpf' => '52998224725'])->state);
        $this->responses[] = new Response(200,['status' => 0,'erroCodigo' => 102]);
        $this->assertSame('no_match',$supplier->lookup('Contact','email',['cpf' => '52998224725'])->state);
    }

    public function testHubUsesContractAndTokenAndRejectsMaskedValues(): void
    {
        $supplier = $this->supplier(Hub::class);
        $this->responses[] = new Response(200,['status' => true,'return' => 'OK','result' => [
            'documento' => '52998224725','listaTelefones' => [['telefoneComDDD' => '(11) 98765-4321'],['telefoneComDDD' => '11987*****']],
            'listaEmails' => [['enderecoEmail' => 'private@example.test']]]]);
        $result = $supplier->lookup('Contact','phone',['cpf' => '52998224725']);
        $this->assertSame(['+5511987654321'],$result->values);
        $this->assertSame(['email' => ['private@example.test']],$result->hidden);
        $this->assertSame('/v2/cadastropf/',$this->calls[0][2]);
        $this->assertSame('fixture-contract',$this->calls[0][3]['contract']);
        $this->assertSame('fixture-token',$this->calls[0][3]['token']);
        $this->responses[] = new Response(200,['status' => 'true','return' => 'OK','result' => [
            'numero_de_inscricao' => '11222333000181','email' => 'company@example.test','telefone' => '(11) 2345-6789']]);
        $this->assertSame(['company@example.test'],$supplier->lookup('Account','email',['cnpj' => '11222333000181'])->values);
        $this->assertSame('/v2/cnpj/',$this->calls[1][2]);
        $this->responses[] = new Response(200,['status' => true,'return' => 'OK','result' => [
            'documento' => '52998224725','listaEmails' => [['enderecoEmail' => 'person****']]]]);
        $this->assertSame('no_match',$supplier->lookup('Contact','email',['cpf' => '52998224725'])->state);
    }

    public function testApolloPhonePollBindsCorrelationAndDisablesNativeWaterfall(): void
    {
        $supplier = $this->supplier(Apollo::class);
        $this->responses[] = new Response(200,['person' => ['id' => 'person-1','linkedin_url' => 'https://www.linkedin.com/in/person-1'], 'request_id' => '9223372036854775807']);
        $pending = $supplier->lookup('Contact','phone',['linkedinUrl' => 'https://linkedin.com/in/person-1/?trk=ignored']);
        $this->assertSame('pending',$pending->state);
        $this->assertSame('9223372036854775807',$pending->requestId);
        $this->assertSame('person-1',$pending->subjectId);
        $this->assertSame('false',$this->calls[0][3]['run_waterfall_email']);
        $this->assertSame('false',$this->calls[0][3]['run_waterfall_phone']);
        $this->assertSame('true',$this->calls[0][3]['poll_only']);
        $this->assertArrayNotHasKey('webhook_url',$this->calls[0][3]);
        $this->assertSame('https://www.linkedin.com/in/person-1',$this->calls[0][3]['linkedin_url']);
        $this->assertArrayNotHasKey('email',$this->calls[0][3]);
        $this->responses[] = new Response(404,['error_code' => 'result_pending','retry_after_seconds' => 20]);
        $this->assertSame(20,$supplier->poll($pending->requestId,'person-1')->retryAfter);
        $body = ['request_id' => $pending->requestId,'request_type' => 'phone','webhook_status' => 'success',
            'webhook_result' => ['status' => 'success','people' => [['id' => 'person-1','phone_numbers' => [
                ['sanitized_number' => '+14155550164','status_cd' => 'valid_number'],
                ['sanitized_number' => '+14155550165','status_cd' => 'invalid_number']]]]]];
        $this->responses[] = new Response(200,$body);
        $this->assertSame(['+14155550164'],$supplier->poll($pending->requestId,'person-1')->values);
        $this->responses[] = new Response(200,$body);
        $this->assertSame('ambiguous',$supplier->poll($pending->requestId,'another-person')->state);
        $this->responses[] = new Response(404,['error_code' => 'request_id_unknown']);
        $this->assertSame('ambiguous',$supplier->poll($pending->requestId,'person-1')->state);
        $this->assertSame(5,count($this->calls),'Polling does not resubmit people/match.');
    }

    public function testApolloEmailRetainsOnlyAlreadyQualifiedIncidentalPhones(): void
    {
        $supplier = $this->supplier(Apollo::class);
        $this->responses[] = new Response(200,['person' => ['id' => 'person-1','linkedin_url' => 'https://www.linkedin.com/in/person-1',
            'email' => 'person@example.test','phone_numbers' => [
                ['sanitized_number' => '+14155550164','status_cd' => 'valid_number'],
                ['sanitized_number' => '+14155550165','status_cd' => 'invalid_number'],
                ['sanitized_number' => '+14155550166'],
                ['sanitized_number' => '+1415555****','status_cd' => 'valid_number']]]]);
        $result = $supplier->lookup('Contact','email',['linkedinUrl' => 'https://www.linkedin.com/in/person-1']);
        $this->assertSame(['person@example.test'],$result->values);
        $this->assertSame(['phone' => ['+14155550164']],$result->hidden);
        $this->assertSame('false',$this->calls[0][3]['reveal_phone_number']);
        $this->assertCount(1,$this->calls);
    }

    public function testApolloCompanyExcludesEmailPhoneAndUnrelatedOrganization(): void
    {
        $supplier = $this->supplier(Apollo::class);
        $body = ['organization' => ['id' => 'company','linkedin_url' => 'https://www.linkedin.com/company/example','name' => 'Example',
            'email' => 'not-a-documented-field@example.test','phone' => '+14155550164','website_url' => 'https://example.test']];
        $this->responses[] = new Response(200,$body);
        $result = $supplier->lookup('Account','company',['linkedinUrl' => 'https://www.linkedin.com/company/example']);
        $this->assertSame(['name' => 'Example','website' => 'https://example.test'],$result->values);
        $this->assertSame(['phone' => ['+14155550164']],$result->hidden);
        $this->assertSame('failed',$supplier->lookup('Account','email',['linkedinUrl' => 'https://www.linkedin.com/company/example'])->state);
        $this->responses[] = new Response(200,$body);
        $this->assertSame('ambiguous',$supplier->lookup('Account','company',['linkedinUrl' => 'https://www.linkedin.com/company/unrelated'])->state);
        $this->assertSame(2,count($this->calls));
    }

    public function testApolloPollingRecoversPayloadAfterWebhookDeliveryFailure(): void
    {
        $supplier = $this->supplier(Apollo::class);
        $body = ['request_id' => '-9223372036854775807', 'request_type' => 'phone', 'webhook_status' => 'failed',
            'webhook_result' => ['status' => 'success', 'people' => [['id' => 'person-1', 'phone_numbers' => [
                ['sanitized_number' => '+14155550164', 'status_cd' => 'valid_number']]]]]];
        $this->responses[] = new Response(200,$body);
        $result = $supplier->poll('-9223372036854775807','person-1');
        $this->assertSame('match',$result->state);
        $this->assertSame(['+14155550164'],$result->values);
        foreach ([
            array_replace($body,['request_id' => '1']),
            array_replace($body,['request_type' => 'waterfall']),
            array_replace($body,['webhook_status' => 'in_progress']),
            array_replace($body,['webhook_status' => 'unknown']),
            array_replace($body,['webhook_result' => null]),
        ] as $invalid) {
            $this->responses[] = new Response(200,$invalid);
            $this->assertSame('ambiguous',$supplier->poll('-9223372036854775807','person-1')->state);
        }
        $this->responses[] = new Response(200,$body);
        $this->assertSame('ambiguous',$supplier->poll('-9223372036854775807','different-person')->state);
        $body['webhook_result']['status'] = 'failed';
        $this->responses[] = new Response(200,$body);
        $this->assertSame('failed',$supplier->poll('-9223372036854775807','person-1')->state);
        $body['webhook_result']['status'] = 'success';
        $body['webhook_result']['people'][0]['phone_numbers'] = [];
        $this->responses[] = new Response(200,$body);
        $this->assertSame('no_match',$supplier->poll('-9223372036854775807','person-1')->state);
        foreach ($this->calls as $call) {
            $this->assertSame('GET',$call[1]);
            $this->assertSame('/api/v1/webhook_result/-9223372036854775807',$call[2]);
        }
    }

    public function testLinkedinProfilesAreOwnEntityTypedAndHostBound(): void
    {
        $normalize = \Espo\Modules\Global\Tools\LinkedInProfile::profile(...);
        $this->assertSame('https://www.linkedin.com/in/person',$normalize('Contact','http://br.linkedin.com/in/person/?trk=example'));
        foreach (['https://evil.test/in/person','https://linkedin.com.evil.test/in/person','https://user:pass@linkedin.com/in/person',
            'https://linkedin.com:444/in/person','https://linkedin.com/company/employer','https://linkedin.com/in/person/activity'] as $url) {
            $this->assertNull($normalize('Contact',$url));
        }
        $this->assertNull($normalize('Account','https://www.linkedin.com/in/person'));
    }

    public function testUnknownMalformedServerAndRedirectOutcomesAreAmbiguousWithoutRetry(): void
    {
        $supplier = $this->supplier(CpfCnpj::class);
        foreach ([new Response(0,null),new Response(500,['error' => 'fail']),new Response(302,null),new Response(200,null),new Response(200,['unexpected' => true])] as $response) {
            $this->responses[] = $response;
            $this->assertSame('ambiguous',$supplier->lookup('Contact','email',['cpf' => '52998224725'])->state);
        }
        $this->assertSame(5,count($this->calls));
        foreach ([401,403,429] as $status) {
            $this->responses[] = new Response($status,[]);
            $this->assertSame('failed',$supplier->lookup('Contact','email',['cpf' => '52998224725'])->state);
        }
    }
}
