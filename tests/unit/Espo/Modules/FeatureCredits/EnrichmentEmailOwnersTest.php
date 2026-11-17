<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Enrichment\{Decisions,EmailOwners};
use Espo\Modules\FeatureCredits\Enrichment\Providers\{CpfCnpj,Credentials,Response,Result,Transport};
use PHPUnit\Framework\TestCase;

class EnrichmentEmailOwnersTest extends TestCase
{
    /** @var list<array{string, array, int}> */
    private array $calls = [];
    /** @var array<string, Response|list<Response>> answer per email; a list is consumed one per call */
    private array $answers = [];
    private string $key = 'sk-fixture';
    private array $subject = ['name' => 'Rodrigo Kim Saito', 'birthYear' => '1996', 'company' => 'Beleza Saúde', 'website' => 'belezasaude.com.br'];

    protected function tearDown(): void
    {
        putenv('ENRICHMENT_EMAIL_OWNER_MIN_PROBABILITY');
    }

    private function owners(): EmailOwners
    {
        $decisions = $this->createStub(Decisions::class);
        $decisions->method('key')->willReturnCallback(fn () => $this->key);
        $decisions->method('ask')->willReturnCallback(function (string $key, array $bodies, int $timeout): array {
            $this->calls[] = [$key, $bodies, $timeout];
            $out = [];
            foreach ($bodies as $index => $body) {
                $answer = $this->answers[$body['state']['email']] ?? new Response(0, null);
                if (is_array($answer)) $answer = array_shift($this->answers[$body['state']['email']]) ?? new Response(0, null);
                $out[$index] = $answer;
            }
            return $out;
        });
        return new EmailOwners($decisions);
    }

    private function apply(Result $result, string $type = 'Contact', string $product = 'email', int $budget = 20000): Result
    {
        return $this->owners()->apply($result, $type, $product, 'cpfcnpj', fn () => $this->subject, $budget);
    }

    private static function choice(string $choice, float $probability): Response
    {
        return new Response(200, ['answers' => ['dono' => ['type' => 'choice', 'choice' => $choice, 'probabilities' => [$choice => $probability]]]]);
    }

    public function testOnlyTheContactsOwnEmailsAreKeptMostCertainFirst(): void
    {
        $this->answers = [
            'digo_kim@hotmail.com' => self::choice('proprio', 0.71),
            'financeiro@belezasaude.com.br' => self::choice('empresa', 0.99),
            'rodrigokimsaito@gmail.com' => self::choice('proprio', 0.99),
            'maria.souza@gmail.com' => self::choice('outra_pessoa', 0.97),
            'mastertitan@gmail.com' => self::choice('proprio', 0.59),
            'abc123@ct.vtex.com.br' => self::choice('sem_relacao', 0.9),
        ];
        $result = $this->apply(new Result('match', ['Digo_Kim@hotmail.com', 'financeiro@belezasaude.com.br', 'rodrigokimsaito@gmail.com',
            'maria.souza@gmail.com', 'mastertitan@gmail.com', 'abc123@ct.vtex.com.br'], hidden: ['phone' => ['+5511987654321']],
            retentionSeconds: 3600, dateOfBirth: '1996-07-02'));
        $this->assertSame('match', $result->state);
        $this->assertSame(['rodrigokimsaito@gmail.com', 'Digo_Kim@hotmail.com'], $result->values);
        $this->assertSame(['phone' => ['+5511987654321']], $result->hidden);
        $this->assertSame(3600, $result->retentionSeconds);
        $this->assertSame('1996-07-02', $result->dateOfBirth);

        $this->assertCount(1, $this->calls);
        [$key, $bodies, $timeout] = $this->calls[0];
        $this->assertSame('sk-fixture', $key);
        $this->assertLessThanOrEqual(8000, $timeout);
        $this->assertCount(6, $bodies);
        $this->assertSame(EmailOwners::MODEL, $bodies[0]['model']);
        $this->assertSame(['data_collection' => 'deny'], $bodies[0]['provider']);
        $this->assertSame(['nome' => 'Rodrigo Kim Saito', 'anoNascimento' => '1996'], $bodies[0]['state']['contato']);
        $this->assertSame(['nome' => 'Beleza Saúde', 'site' => 'belezasaude.com.br'], $bodies[0]['state']['empresaDoContato']);
        $this->assertSame('digo_kim@hotmail.com', $bodies[0]['state']['email']);
        $this->assertCount(5, $bodies[0]['state']['outrosEmailsRetornados']);
        $this->assertNotContains('digo_kim@hotmail.com', $bodies[0]['state']['outrosEmailsRetornados']);
        $this->assertSame(['proprio', 'empresa', 'outra_pessoa', 'sem_relacao'], array_keys($bodies[0]['questions']['dono']['criteria']));
    }

    public function testALookupWithNoOwnEmailIsNoMatch(): void
    {
        $this->answers = ['maria.souza@gmail.com' => self::choice('outra_pessoa', 0.97), 'contato@loja.com.br' => self::choice('empresa', 0.99)];
        $result = $this->apply(new Result('match', array_keys($this->answers), dateOfBirth: '1996-07-02'));
        $this->assertSame('no_match', $result->state);
        $this->assertSame([], $result->values);
        $this->assertSame('1996-07-02', $result->dateOfBirth);
    }

    public function testTheCutoffIsConfigurable(): void
    {
        $this->answers = ['a@x.com' => self::choice('proprio', 0.75), 'b@x.com' => self::choice('proprio', 0.85)];
        putenv('ENRICHMENT_EMAIL_OWNER_MIN_PROBABILITY=0.8');
        $this->assertSame(['b@x.com'], $this->apply(new Result('match', ['a@x.com', 'b@x.com']))->values);
        putenv('ENRICHMENT_EMAIL_OWNER_MIN_PROBABILITY=0.2');
        $this->assertSame(0.6, EmailOwners::minimumProbability(), 'Values outside 0.5–1 fall back to the default.');
    }

    public function testAnUnansweredEmailIsResentOnceThenTheLookupFails(): void
    {
        $this->answers = ['a@x.com' => self::choice('proprio', 0.9), 'b@x.com' => [new Response(429, null), self::choice('proprio', 0.8)]];
        $this->assertSame(['a@x.com', 'b@x.com'], $this->apply(new Result('match', ['a@x.com', 'b@x.com']))->values);
        $this->assertCount(2, $this->calls);
        $this->assertSame([1], array_keys($this->calls[1][1]), 'Only the unanswered email is resent.');

        $this->calls = [];
        $this->answers = ['a@x.com' => self::choice('proprio', 0.9), 'b@x.com' => new Response(503, null)];
        $result = $this->apply(new Result('match', ['a@x.com', 'b@x.com'], dateOfBirth: '1996-07-02'));
        $this->assertSame('failed', $result->state, 'Unchecked emails are never bought.');
        $this->assertSame([], $result->values);
        $this->assertCount(2, $this->calls);

        foreach ([['answers' => ['dono' => ['type' => 'choice', 'choice' => 'talvez', 'probabilities' => ['talvez' => 0.9]]]],
            ['answers' => ['dono' => ['type' => 'choice', 'choice' => 'proprio', 'probabilities' => ['proprio' => 1.4]]]],
            ['answers' => ['dono' => ['type' => 'boolean', 'value' => true]]], ['answers' => []]] as $body) {
            $this->answers = ['a@x.com' => new Response(200, $body)];
            $this->assertSame('failed', $this->apply(new Result('match', ['a@x.com']))->state);
        }
    }

    public function testAnExhaustedLeaseOrAnUnknownContactFailsWithoutACall(): void
    {
        $this->answers = ['a@x.com' => self::choice('proprio', 0.9)];
        $this->assertSame('failed', $this->apply(new Result('match', ['a@x.com']), budget: 500)->state);
        $this->subject = ['name' => ' '];
        $this->assertSame('failed', $this->apply(new Result('match', ['a@x.com']))->state);
        $this->assertSame([], $this->calls);
    }

    public function testSiblingEmailsAreFilteredAndNeverRetainedUnchecked(): void
    {
        $this->answers = ['a@x.com' => self::choice('proprio', 0.9), 'b@x.com' => self::choice('outra_pessoa', 0.9)];
        $result = $this->apply(new Result('match', ['+5511987654321'], hidden: ['email' => ['a@x.com', 'b@x.com']]), product: 'phone');
        $this->assertSame('match', $result->state);
        $this->assertSame(['+5511987654321'], $result->values);
        $this->assertSame(['email' => ['a@x.com']], $result->hidden);

        $result = $this->apply(new Result('no_match', hidden: ['email' => ['b@x.com']]), product: 'phone');
        $this->assertSame('no_match', $result->state);
        $this->assertSame([], $result->hidden);

        $this->answers = [];
        $result = $this->apply(new Result('match', ['+5511987654321'], hidden: ['email' => ['a@x.com']]), product: 'phone');
        $this->assertSame('match', $result->state, 'The phone lookup stands when the sibling emails cannot be checked.');
        $this->assertSame([], $result->hidden);
    }

    public function testEverythingElsePassesThroughUntouched(): void
    {
        $match = new Result('match', ['contato@empresa.com.br']);
        $this->assertSame($match, $this->apply($match, type: 'Account'), 'Company emails are out of scope.');
        $phone = new Result('match', ['+5511987654321']);
        $this->assertSame($phone, $this->apply($phone, product: 'phone'));
        foreach ([new Result('failed'), new Result('ambiguous'), new Result('no_match'), new Result('pending', requestId: '1')] as $result) {
            $this->assertSame($result, $this->apply($result));
        }
        $this->key = '';
        $this->assertSame($match, $this->apply($match), 'Without the platform key nothing is checked.');
        $this->assertSame([], $this->calls);
    }

    public function testContactEmailRevisionCarriesTheMarker(): void
    {
        $credentials = $this->createStub(Credentials::class);
        $credentials->method('get')->willReturn(['token' => 'fixture']);
        $supplier = new CpfCnpj($credentials, $this->createStub(Transport::class));
        $identity = ['cpf' => '52998224725', 'cnpj' => '11222333000181'];
        $legacy = fn (string $type) => hash('sha256', json_encode(['supplier-contract-2026-10-11-v1', 'retention-reuse-approved-max90d',
            'cpfcnpj', 'tenant', $type, 'email', ['token' => 'fixture']], JSON_THROW_ON_ERROR));
        $this->assertNotSame($legacy('Contact'), $supplier->revision('tenant', 'Contact', 'email', $identity));
        $this->assertSame($legacy('Account'), $supplier->revision('tenant', 'Account', 'email', $identity));
    }
}
