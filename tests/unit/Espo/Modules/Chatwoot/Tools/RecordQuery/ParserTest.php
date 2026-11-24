<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Tools\RecordQuery;

use Espo\Modules\Chatwoot\Tools\RecordQuery\Parser;
use Espo\Modules\Chatwoot\Tools\RecordQuery\QueryError;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private function parse(string $query): array
    {
        return (new Parser())->parse($query);
    }

    private function error(string $query): string
    {
        try {
            $this->parse($query);
        } catch (QueryError $error) {
            return $error->errorCode;
        }
        $this->fail("Parsed: $query");
    }

    public function testListMethodsChainWhereThenQuantifier(): void
    {
        $query = 'contacts.where(origin = "enrichment").all(enrichmentResults.any(valid = true))';
        $this->assertSame(
            ['list', ['contacts'], 'all', [['cmp', ['origin'], '=', ['str', 'enrichment'], 15]],
                ['list', ['enrichmentResults'], 'any', [], ['cmp', ['valid'], '=', ['bool', true], strpos($query, 'valid')],
                    strpos($query, 'enrichmentResults')], 0],
            $this->parse($query),
        );
        $this->assertSame(['list', ['contacts'], 'any', [['cmp', ['a'], '=', ['num', '1'], 15], ['cmp', ['b'], '=', ['num', '2'], 28]], null, 0],
            $this->parse('contacts.where(a = 1).where(b = 2)'));
        $this->assertSame(['list', ['contacts'], 'none', [], null, 0], $this->parse('contacts.none()'));
        $this->assertSame(['list', ['account', 'contacts'], 'all', [], ['cmp', ['x'], '=', ['num', '1'], 21], 0], $this->parse('account.contacts.ALL(x = 1)'));
    }

    public function testShorthandsKeepTheirMeaning(): void
    {
        $this->assertSame(['list', ['contacts'], 'any', [], ['cmp', ['name'], '~', ['str', 'a'], 9], 0], $this->parse('contacts(name ~ "a")'));
        $this->assertSame(['list', ['contacts'], 'any', [], null, 0], $this->parse('contacts()'));
        $this->assertSame(['cmp', ['account', 'name'], '=', ['str', 'x'], 0], $this->parse('account.name = "x"'));
        $this->assertSame(['cmp', ['related', 'Account'], 'empty', null, 0], $this->parse('related.Account IS EMPTY'));
        // A field that happens to share a method name is still a field when no bracket follows.
        $this->assertSame(['cmp', ['stats', 'count'], '>', ['num', '3'], 0], $this->parse('stats.count > 3'));
    }

    public function testCount(): void
    {
        $this->assertSame(['count', ['contacts'], [['cmp', ['origin'], '=', ['str', 'enrichment'], 15]], null, '>=', 2, 0],
            $this->parse('contacts.where(origin = "enrichment").count() >= 2'));
        $this->assertSame(['count', ['contacts'], [], ['cmp', ['x'], '=', ['num', '1'], 15], '=', 0, 0], $this->parse('contacts.count(x = 1) = 0'));
        $this->assertSame('expected_count', $this->error('contacts.count() > 1.5'));
        $this->assertSame('expected_count', $this->error('contacts.count() > -1'));
        $this->assertSame('expected_count', $this->error('contacts.count() > "2"'));
        $this->assertSame('expected_operator', $this->error('contacts.count() ~ 2'));
        $this->assertSame('unexpected_end', $this->error('contacts.count()'));
    }

    public function testMalformedListsAreRejected(): void
    {
        $this->assertSame('expected_condition', $this->error('contacts.where()'));
        $this->assertSame('expected_method', $this->error('contacts.where(a = 1).name = "x"'));
        $this->assertSame('unexpected_token', $this->error('contacts.any(a = 1).all(b = 1)'));
        $this->assertSame('unexpected_end', $this->error('contacts.any(a = 1'));
        $this->assertSame('expected_close', $this->error('contacts.any(a = 1 b'));
    }

    public function testInjectionAttemptsStayDataOrFail(): void
    {
        $this->assertSame(['cmp', ['name'], '=', ['str', "x' OR '1'='1"], 0], $this->parse('name = "x\' OR \'1\'=\'1"'));
        $this->assertSame(['cmp', ['name'], '=', ['str', '"; DROP TABLE account; --'], 0], $this->parse('name = "\\"; DROP TABLE account; --"'));
        $this->assertSame('unexpected_character', $this->error('name = 1; DROP TABLE account'));
        $this->assertSame('unexpected_character', $this->error('`name` = 1'));
        $this->assertSame('unexpected_character', $this->error('name/**/= 1'));
    }
}
