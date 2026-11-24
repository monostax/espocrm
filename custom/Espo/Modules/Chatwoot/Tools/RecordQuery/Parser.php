<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\RecordQuery;

/**
 * Record query text to a tree. No metadata here: names are resolved by the Compiler.
 *
 *   source = "Indicação" AND (account.industry IN ("SaaS", "Fintech") OR tags HAS "vip")
 *   AND opportunities.any(stage = "Won" AND amount > 1000) AND createdAt > -30d AND NOT assignedUser IS EMPTY
 *   AND contacts.where(origin = "enrichment").all(enrichmentResults.any(type = "email" AND valid = true))
 *
 * A relationship to one record reads like an object (`account.industry`); a relationship to many reads like a list and
 * says how many must match: `.any(…)`, `.all(…)` (at least one, and every one), `.none(…)`, `.count(…) >= 2`, with
 * `.where(…)` narrowing the list first. `contacts(…)` is short for `contacts.any(…)`.
 *
 * Nodes:
 *   ['and'|'or', list<node>]
 *   ['not', node]
 *   ['list', path, 'any'|'all'|'none', list<node> where, ?node test, position]
 *   ['count', path, list<node> where, ?node test, operator, int, position]
 *   ['cmp', path, operator, value, position]   operator: = != > >= < <= ~ !~ in notIn empty notEmpty
 * Values: ['str', string] ['num', string] ['bool', bool] ['kw', 'today'|'me'] ['ago', int, 'd'|'w'|'m'|'y'].
 */
final class Parser
{
    private const OPERATORS = ['!=', '>=', '<=', '!~', '=', '>', '<', '~', '(', ')', ',', '.'];
    private const METHODS = ['any', 'all', 'none', 'where', 'count'];
    private const COUNT_OPERATORS = ['=', '!=', '>', '>=', '<', '<='];

    /** @var list<array{string, mixed, int}> */
    private array $tokens = [];
    private int $at = 0;

    /** @return array<int, mixed> */
    public function parse(string $text): array
    {
        $this->tokens = $this->tokenize($text);
        $this->at = 0;
        if ($this->tokens[0][0] === 'end') throw QueryError::at('empty', 0);
        $node = $this->either();
        if ($this->peek()[0] !== 'end') $this->unexpected();
        return $node;
    }

    /** @return list<array{string, mixed, int}> */
    private function tokenize(string $text): array
    {
        $tokens = [];
        $length = strlen($text);
        $i = 0;
        while ($i < $length) {
            $char = $text[$i];
            if (ctype_space($char)) {
                $i++;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $value = '';
                for ($j = $i + 1; $j < $length && $text[$j] !== $char; $j++) {
                    if ($text[$j] === '\\' && $j + 1 < $length) $j++;
                    $value .= $text[$j];
                }
                if ($j >= $length) throw QueryError::at('unterminated_string', $i);
                $tokens[] = ['str', $value, $i];
                $i = $j + 1;
                continue;
            }
            if (preg_match('/\G[+-]?\d+(\.\d+)?/', $text, $match, 0, $i)) {
                $number = $match[0];
                $end = $i + strlen($number);
                $signed = $char === '+' || $char === '-';
                if ($signed && !isset($match[1]) && preg_match('/\G[dwmy](?![A-Za-z0-9_])/', $text, $unit, 0, $end)) {
                    $tokens[] = ['ago', [(int) $number, $unit[0]], $i];
                    $i = $end + 1;
                    continue;
                }
                $tokens[] = ['num', $number, $i];
                $i = $end;
                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $text, $match, 0, $i)) {
                $tokens[] = ['word', $match[0], $i];
                $i += strlen($match[0]);
                continue;
            }
            foreach (self::OPERATORS as $operator) {
                if (substr($text, $i, strlen($operator)) === $operator) {
                    $tokens[] = ['op', $operator, $i];
                    $i += strlen($operator);
                    continue 2;
                }
            }
            throw QueryError::at('unexpected_character', $i, mb_substr(substr($text, $i), 0, 1));
        }
        $tokens[] = ['end', null, $length];
        return $tokens;
    }

    /** @return array{string, mixed, int} */
    private function peek(): array
    {
        return $this->tokens[$this->at];
    }

    private function isWord(string $keyword): bool
    {
        [$type, $value] = $this->peek();
        return $type === 'word' && strtoupper($value) === $keyword;
    }

    private function isOp(string $operator): bool
    {
        [$type, $value] = $this->peek();
        return $type === 'op' && $value === $operator;
    }

    /** A list method name followed by its opening bracket, at token $at. */
    private function isMethodAt(int $at): bool
    {
        [$type, $value] = $this->tokens[$at];
        if ($type !== 'word' || !in_array(strtolower($value), self::METHODS, true)) return false;
        [$type, $value] = $this->tokens[$at + 1];
        return $type === 'op' && $value === '(';
    }

    private function takeWord(string $keyword): bool
    {
        if (!$this->isWord($keyword)) return false;
        $this->at++;
        return true;
    }

    private function takeOp(string $operator): bool
    {
        if (!$this->isOp($operator)) return false;
        $this->at++;
        return true;
    }

    private function unexpected(string $code = 'unexpected_token'): never
    {
        [$type, $value, $position] = $this->peek();
        if ($type === 'end') throw QueryError::at('unexpected_end', $position);
        throw QueryError::at($code, $position, is_array($value) ? null : (string) $value);
    }

    /** @return array<int, mixed> */
    private function either(): array
    {
        $nodes = [$this->both()];
        while ($this->takeWord('OR')) $nodes[] = $this->both();
        return count($nodes) === 1 ? $nodes[0] : ['or', $nodes];
    }

    /** @return array<int, mixed> */
    private function both(): array
    {
        $nodes = [$this->unary()];
        while ($this->takeWord('AND')) $nodes[] = $this->unary();
        return count($nodes) === 1 ? $nodes[0] : ['and', $nodes];
    }

    /** @return array<int, mixed> */
    private function unary(): array
    {
        if ($this->takeWord('NOT')) return ['not', $this->unary()];
        if ($this->takeOp('(')) {
            $node = $this->either();
            if (!$this->takeOp(')')) $this->unexpected('expected_close');
            return $node;
        }
        return $this->condition();
    }

    /** @return array<int, mixed> */
    private function condition(): array
    {
        [$type, $name, $position] = $this->peek();
        if ($type !== 'word') $this->unexpected('expected_field');
        $this->at++;
        $path = [$name];
        while ($this->isOp('.')) {
            $this->at++;
            if ($this->isMethodAt($this->at)) return $this->methods($path, $position);
            [$type, $name] = $this->peek();
            if ($type !== 'word') $this->unexpected('expected_field');
            $this->at++;
            $path[] = $name;
        }
        if ($this->takeOp('(')) {
            $inner = $this->isOp(')') ? null : $this->either();
            if (!$this->takeOp(')')) $this->unexpected('expected_close');
            return ['list', $path, 'any', [], $inner, $position];
        }
        if ($this->takeWord('IS')) {
            $negated = $this->takeWord('NOT');
            if (!$this->takeWord('EMPTY')) $this->unexpected('expected_empty');
            return ['cmp', $path, $negated ? 'notEmpty' : 'empty', null, $position];
        }
        if ($this->takeWord('NOT')) {
            if (!$this->takeWord('IN')) $this->unexpected('expected_operator');
            return ['cmp', $path, 'notIn', $this->values(), $position];
        }
        if ($this->takeWord('IN')) return ['cmp', $path, 'in', $this->values(), $position];
        if ($this->takeWord('HAS')) return ['cmp', $path, '=', $this->value(), $position];
        [$type, $operator] = $this->peek();
        if ($type !== 'op' || in_array($operator, ['(', ')', ',', '.'], true)) $this->unexpected('expected_operator');
        $this->at++;
        return ['cmp', $path, $operator, $this->value(), $position];
    }

    /**
     * `path.where(…).where(…).all(…)`, entered on the first method name.
     *
     * @param list<string> $path
     * @return array<int, mixed>
     */
    private function methods(array $path, int $position): array
    {
        $where = [];
        while (true) {
            [, $name, $at] = $this->peek();
            $method = strtolower($name);
            $this->at += 2;
            $inner = $this->isOp(')') ? null : $this->either();
            if (!$this->takeOp(')')) $this->unexpected('expected_close');
            if ($method === 'count') {
                [$type, $operator] = $this->peek();
                if ($type !== 'op' || !in_array($operator, self::COUNT_OPERATORS, true)) $this->unexpected('expected_operator');
                $this->at++;
                [$type, $number] = $this->peek();
                if ($type !== 'num' || !preg_match('/^\d{1,9}$/D', $number)) $this->unexpected('expected_count');
                $this->at++;
                return ['count', $path, $where, $inner, $operator, (int) $number, $position];
            }
            if ($method !== 'where') return ['list', $path, $method, $where, $inner, $position];
            if ($inner === null) throw QueryError::at('expected_condition', $at, $name);
            $where[] = $inner;
            if (!$this->isOp('.')) return ['list', $path, 'any', $where, null, $position];
            $this->at++;
            if (!$this->isMethodAt($this->at)) $this->unexpected('expected_method');
        }
    }

    /** @return list<array<int, mixed>> */
    private function values(): array
    {
        if (!$this->takeOp('(')) $this->unexpected('expected_list');
        $values = [$this->value()];
        while ($this->takeOp(',')) $values[] = $this->value();
        if (!$this->takeOp(')')) $this->unexpected('expected_close');
        return $values;
    }

    /** @return array<int, mixed> */
    private function value(): array
    {
        [$type, $value] = $this->peek();
        $keyword = $type === 'word' ? strtoupper($value) : null;
        $result = match (true) {
            $type === 'str', $type === 'num' => [$type, $value],
            $type === 'ago' => ['ago', $value[0], $value[1]],
            $keyword === 'TRUE', $keyword === 'FALSE' => ['bool', $keyword === 'TRUE'],
            $keyword === 'TODAY', $keyword === 'ME' => ['kw', strtolower($keyword)],
            default => $this->unexpected('expected_value'),
        };
        $this->at++;
        return $result;
    }
}
