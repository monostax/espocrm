<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\RecordQuery;

/**
 * Record query text to a tree. No metadata here: names are resolved by the Compiler.
 *
 *   source = "Indicação" AND (account.industry IN ("SaaS", "Fintech") OR tags HAS "vip")
 *   AND opportunities(stage = "Won" AND amount > 1000) AND createdAt > -30d AND NOT assignedUser IS EMPTY
 *
 * Nodes:
 *   ['and'|'or', list<node>]
 *   ['not', node]
 *   ['rel', path, ?node, position]             link(...) - has a related record matching all of it
 *   ['cmp', path, operator, value, position]   operator: = != > >= < <= ~ !~ in notIn empty notEmpty
 * Values: ['str', string] ['num', string] ['bool', bool] ['kw', 'today'|'me'] ['ago', int, 'd'|'w'|'m'|'y'].
 */
final class Parser
{
    private const OPERATORS = ['!=', '>=', '<=', '!~', '=', '>', '<', '~', '(', ')', ',', '.'];

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
        while ($this->takeOp('.')) {
            [$type, $name] = $this->peek();
            if ($type !== 'word') $this->unexpected('expected_field');
            $this->at++;
            $path[] = $name;
        }
        if ($this->takeOp('(')) {
            $inner = $this->isOp(')') ? null : $this->either();
            if (!$this->takeOp(')')) $this->unexpected('expected_close');
            return ['rel', $path, $inner, $position];
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
