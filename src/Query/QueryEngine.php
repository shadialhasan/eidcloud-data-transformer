<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer\Query;

/**
 * Embedded SQL-like query and filtering engine.
 * Supports SELECT projections, WHERE conditions with logical operators,
 * SORT ordering, LIMIT and OFFSET.
 */
class QueryEngine
{
    private ?array $selectColumns = null;
    private ?string $whereClause = null;
    private ?array $sortRules = null;
    private ?int $limit = null;
    private int $offset = 0;
    private ?\Closure $wherePredicate = null;

    public function __construct(
        ?string $select = null,
        ?string $where = null,
        ?string $sort = null,
        ?int $limit = null,
        int $offset = 0
    ) {
        if ($select !== null && trim($select) !== '' && trim($select) !== '*') {
            $this->setSelect($select);
        }
        if ($where !== null && trim($where) !== '') {
            $this->setWhere($where);
        }
        if ($sort !== null && trim($sort) !== '') {
            $this->setSort($sort);
        }
        $this->limit = $limit;
        $this->offset = max(0, $offset);
    }

    /**
     * Configure SELECT projection columns.
     */
    public function setSelect(string|array $select): self
    {
        $columns = [];
        $rawCols = is_array($select) ? $select : explode(',', $select);

        foreach ($rawCols as $col) {
            $col = trim((string)$col);
            if ($col === '' || $col === '*') {
                continue;
            }
            // Check for alias: "col AS alias" or "col alias"
            if (preg_match('/^(.*?)\s+(?:as\s+)?([a-zA-Z0-9_]+)$/i', $col, $matches)) {
                $orig = trim($matches[1]);
                $alias = trim($matches[2]);
                $columns[$alias] = $orig;
            } else {
                $columns[$col] = $col;
            }
        }

        $this->selectColumns = !empty($columns) ? $columns : null;
        return $this;
    }

    /**
     * Configure WHERE filter condition.
     */
    public function setWhere(string $where): self
    {
        $this->whereClause = trim($where);
        if ($this->whereClause !== '') {
            $this->wherePredicate = $this->compileWherePredicate($this->whereClause);
        } else {
            $this->wherePredicate = null;
        }
        return $this;
    }

    /**
     * Configure SORT rules (e.g. "created_at:desc, age:asc" or "id desc").
     */
    public function setSort(string|array $sort): self
    {
        $rules = [];
        $parts = is_array($sort) ? $sort : explode(',', $sort);

        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, ':')) {
                [$field, $dir] = explode(':', $part, 2);
            } elseif (preg_match('/^(\S+)\s+(\S+)$/', $part, $m)) {
                $field = $m[1];
                $dir = $m[2];
            } else {
                $field = $part;
                $dir = 'asc';
            }

            $field = trim($field);
            $dir = strtolower(trim($dir)) === 'desc' ? 'desc' : 'asc';
            $rules[] = ['field' => $field, 'direction' => $dir];
        }

        $this->sortRules = !empty($rules) ? $rules : null;
        return $this;
    }

    /**
     * Configure LIMIT and OFFSET.
     */
    public function setLimit(?int $limit, int $offset = 0): self
    {
        $this->limit = $limit;
        $this->offset = max(0, $offset);
        return $this;
    }

    /**
     * Execute query on iterable dataset.
     *
     * @param iterable<array<string, mixed>> $rows
     * @return \Generator<int, array<string, mixed>>
     */
    public function apply(iterable $rows): \Generator
    {
        // Step 1: Filter and Project
        $filtered = $this->filterAndProject($rows);

        // Step 2: If sorting is requested, we must buffer and sort
        if ($this->sortRules !== null) {
            $buffer = iterator_to_array($filtered, false);
            $this->sortBuffer($buffer);

            // Apply offset and limit
            $sliced = array_slice($buffer, $this->offset, $this->limit ?? null);
            foreach ($sliced as $record) {
                yield $record;
            }
            return;
        }

        // Step 3: Lazy streaming when no sorting is requested
        $skipped = 0;
        $emitted = 0;

        foreach ($filtered as $record) {
            if ($skipped < $this->offset) {
                $skipped++;
                continue;
            }

            if ($this->limit !== null && $emitted >= $this->limit) {
                break;
            }

            yield $record;
            $emitted++;
        }
    }

    /**
     * Lazily filter and project rows.
     *
     * @param iterable<array<string, mixed>> $rows
     * @return \Generator<int, array<string, mixed>>
     */
    private function filterAndProject(iterable $rows): \Generator
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // WHERE check
            if ($this->wherePredicate !== null) {
                if (!($this->wherePredicate)($row)) {
                    continue;
                }
            }

            // SELECT projection
            if ($this->selectColumns !== null) {
                $projected = [];
                foreach ($this->selectColumns as $outputKey => $sourceKey) {
                    $projected[$outputKey] = $this->extractRowValue($row, $sourceKey);
                }
                yield $projected;
            } else {
                yield $row;
            }
        }
    }

    /**
     * Extract row value case-insensitively if not found directly.
     */
    private function extractRowValue(array $row, string $key): mixed
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }

        // Case-insensitive lookup
        $lowerKey = strtolower($key);
        foreach ($row as $k => $v) {
            if (strtolower((string)$k) === $lowerKey) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Sort an in-memory buffer using configured rules.
     */
    private function sortBuffer(array &$buffer): void
    {
        if (empty($this->sortRules)) {
            return;
        }

        usort($buffer, function ($a, $b) {
            foreach ($this->sortRules as $rule) {
                $field = $rule['field'];
                $dir = $rule['direction'] === 'desc' ? -1 : 1;

                $valA = $this->extractRowValue($a, $field);
                $valB = $this->extractRowValue($b, $field);

                if ($valA === $valB) {
                    continue;
                }

                if ($valA === null) {
                    return -1 * $dir;
                }
                if ($valB === null) {
                    return 1 * $dir;
                }

                if (is_numeric($valA) && is_numeric($valB)) {
                    $cmp = ((float)$valA <=> (float)$valB);
                } else {
                    $cmp = strcasecmp((string)$valA, (string)$valB);
                }

                if ($cmp !== 0) {
                    return $cmp * $dir;
                }
            }
            return 0;
        });
    }

    /**
     * Compile WHERE clause into an executable predicate closure.
     */
    private function compileWherePredicate(string $clause): \Closure
    {
        $tokens = $this->tokenizeWhere($clause);
        $pos = 0;
        $node = $this->parseOrExpression($tokens, $pos);

        return function (array $row) use ($node): bool {
            return (bool)$this->evaluateNode($node, $row);
        };
    }

    /**
     * Tokenize WHERE clause into tokens.
     *
     * @return array<int, array{type: string, value: string}>
     */
    private function tokenizeWhere(string $clause): array
    {
        $tokens = [];
        $len = strlen($clause);
        $i = 0;

        while ($i < $len) {
            $char = $clause[$i];

            // Whitespace
            if (ctype_space($char)) {
                $i++;
                continue;
            }

            // Parentheses and commas
            if ($char === '(' || $char === ')' || $char === ',') {
                $tokens[] = ['type' => $char, 'value' => $char];
                $i++;
                continue;
            }

            // Multi-char operators: <=, >=, !=, <>
            if ($i + 1 < $len) {
                $two = substr($clause, $i, 2);
                if (in_array($two, ['<=', '>=', '!=', '<>'], true)) {
                    $tokens[] = ['type' => 'OP', 'value' => $two === '<>' ? '!=' : $two];
                    $i += 2;
                    continue;
                }
            }

            // Single-char operators: =, <, >
            if (in_array($char, ['=', '<', '>'], true)) {
                $tokens[] = ['type' => 'OP', 'value' => $char];
                $i++;
                continue;
            }

            // Quoted strings
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $str = '';
                $i++;
                while ($i < $len) {
                    if ($clause[$i] === '\\' && $i + 1 < $len) {
                        $str .= $clause[$i + 1];
                        $i += 2;
                    } elseif ($clause[$i] === $quote) {
                        $i++;
                        break;
                    } else {
                        $str .= $clause[$i];
                        $i++;
                    }
                }
                $tokens[] = ['type' => 'LITERAL', 'value' => $str];
                continue;
            }

            // Words or numbers
            $word = '';
            while ($i < $len && !ctype_space($clause[$i]) && !in_array($clause[$i], ['(', ')', ',', '=', '<', '>', "'", '"'], true)) {
                $word .= $clause[$i];
                $i++;
            }

            $upper = strtoupper($word);
            if (in_array($upper, ['AND', 'OR', 'NOT', 'LIKE', 'IN', 'IS', 'NULL'], true)) {
                $tokens[] = ['type' => $upper, 'value' => $upper];
            } else {
                $tokens[] = ['type' => 'IDENTIFIER', 'value' => $word];
            }
        }

        return $tokens;
    }

    /**
     * Parse OR expression: and_expr ( 'OR' and_expr )*
     */
    private function parseOrExpression(array &$tokens, int &$pos): array
    {
        $node = $this->parseAndExpression($tokens, $pos);

        while ($pos < count($tokens) && $tokens[$pos]['type'] === 'OR') {
            $pos++; // consume OR
            $right = $this->parseAndExpression($tokens, $pos);
            $node = [
                'type' => 'OR',
                'left' => $node,
                'right' => $right,
            ];
        }

        return $node;
    }

    /**
     * Parse AND expression: not_expr ( 'AND' not_expr )*
     */
    private function parseAndExpression(array &$tokens, int &$pos): array
    {
        $node = $this->parseNotExpression($tokens, $pos);

        while ($pos < count($tokens) && $tokens[$pos]['type'] === 'AND') {
            $pos++; // consume AND
            $right = $this->parseNotExpression($tokens, $pos);
            $node = [
                'type' => 'AND',
                'left' => $node,
                'right' => $right,
            ];
        }

        return $node;
    }

    /**
     * Parse NOT expression: 'NOT'? primary
     */
    private function parseNotExpression(array &$tokens, int &$pos): array
    {
        if ($pos < count($tokens) && $tokens[$pos]['type'] === 'NOT') {
            $pos++;
            $inner = $this->parseNotExpression($tokens, $pos);
            return [
                'type' => 'NOT',
                'child' => $inner,
            ];
        }

        return $this->parsePrimary($tokens, $pos);
    }

    /**
     * Parse primary expression: '(' expr ')' or comparison
     */
    private function parsePrimary(array &$tokens, int &$pos): array
    {
        if ($pos < count($tokens) && $tokens[$pos]['type'] === '(') {
            $pos++; // consume '('
            $node = $this->parseOrExpression($tokens, $pos);
            if ($pos < count($tokens) && $tokens[$pos]['type'] === ')') {
                $pos++; // consume ')'
            }
            return $node;
        }

        return $this->parseComparison($tokens, $pos);
    }

    /**
     * Parse comparison: column OP value, column IS (NOT) NULL, column (NOT) IN (...), column (NOT) LIKE pattern
     */
    private function parseComparison(array &$tokens, int &$pos): array
    {
        if ($pos >= count($tokens)) {
            return ['type' => 'TRUE'];
        }

        $colToken = $tokens[$pos++];
        $column = $colToken['value'];

        if ($pos >= count($tokens)) {
            // Bare truthiness check
            return ['type' => 'COMPARE', 'col' => $column, 'op' => '!=', 'val' => ''];
        }

        $next = $tokens[$pos];

        // Case 1: IS [NOT] NULL
        if ($next['type'] === 'IS') {
            $pos++;
            $isNot = false;
            if ($pos < count($tokens) && $tokens[$pos]['type'] === 'NOT') {
                $isNot = true;
                $pos++;
            }
            if ($pos < count($tokens) && $tokens[$pos]['type'] === 'NULL') {
                $pos++;
            }
            return [
                'type' => $isNot ? 'IS_NOT_NULL' : 'IS_NULL',
                'col' => $column,
            ];
        }

        // Case 2: [NOT] LIKE pattern
        $isNotLike = false;
        if ($next['type'] === 'NOT' && isset($tokens[$pos + 1]) && $tokens[$pos + 1]['type'] === 'LIKE') {
            $isNotLike = true;
            $pos += 2;
            $patternToken = $tokens[$pos++];
            return [
                'type' => 'LIKE',
                'not' => true,
                'col' => $column,
                'pattern' => $patternToken['value'],
            ];
        } elseif ($next['type'] === 'LIKE') {
            $pos++;
            $patternToken = $tokens[$pos++];
            return [
                'type' => 'LIKE',
                'not' => false,
                'col' => $column,
                'pattern' => $patternToken['value'],
            ];
        }

        // Case 3: [NOT] IN (val1, val2, ...)
        $isNotIn = false;
        if ($next['type'] === 'NOT' && isset($tokens[$pos + 1]) && $tokens[$pos + 1]['type'] === 'IN') {
            $isNotIn = true;
            $pos += 2;
        } elseif ($next['type'] === 'IN') {
            $pos++;
        }

        if (isset($tokens[$pos]) && $tokens[$pos]['type'] === '(') {
            $pos++; // consume '('
            $values = [];
            while ($pos < count($tokens) && $tokens[$pos]['type'] !== ')') {
                if ($tokens[$pos]['type'] === ',') {
                    $pos++;
                    continue;
                }
                $values[] = $tokens[$pos]['value'];
                $pos++;
            }
            if ($pos < count($tokens) && $tokens[$pos]['type'] === ')') {
                $pos++;
            }
            return [
                'type' => 'IN',
                'not' => $isNotIn,
                'col' => $column,
                'values' => $values,
            ];
        }

        // Case 4: Standard binary operator (=, !=, <, >, <=, >=)
        if ($next['type'] === 'OP') {
            $op = $next['value'];
            $pos++;
            if ($pos < count($tokens)) {
                $valToken = $tokens[$pos++];
                return [
                    'type' => 'COMPARE',
                    'col' => $column,
                    'op' => $op,
                    'val' => $valToken['value'],
                ];
            }
        }

        return ['type' => 'TRUE'];
    }

    /**
     * Evaluate parsed AST node against a single row.
     */
    private function evaluateNode(array $node, array $row): bool
    {
        return match ($node['type']) {
            'TRUE' => true,
            'NOT' => !$this->evaluateNode($node['child'], $row),
            'AND' => $this->evaluateNode($node['left'], $row) && $this->evaluateNode($node['right'], $row),
            'OR' => $this->evaluateNode($node['left'], $row) || $this->evaluateNode($node['right'], $row),
            'IS_NULL' => $this->checkIsNull($this->extractRowValue($row, $node['col'])),
            'IS_NOT_NULL' => !$this->checkIsNull($this->extractRowValue($row, $node['col'])),
            'IN' => $this->checkIn($this->extractRowValue($row, $node['col']), $node['values'], $node['not'] ?? false),
            'LIKE' => $this->checkLike($this->extractRowValue($row, $node['col']), $node['pattern'], $node['not'] ?? false),
            'COMPARE' => $this->compareValues($this->extractRowValue($row, $node['col']), $node['op'], $node['val']),
            default => true,
        };
    }

    private function checkIsNull(mixed $val): bool
    {
        return $val === null || $val === '' || (is_string($val) && strtolower($val) === 'null');
    }

    private function checkIn(mixed $val, array $values, bool $not): bool
    {
        $valStr = (string)($val ?? '');
        $found = false;
        foreach ($values as $target) {
            if (is_numeric($val) && is_numeric($target)) {
                if ((float)$val === (float)$target) {
                    $found = true;
                    break;
                }
            } elseif (strcasecmp($valStr, (string)$target) === 0) {
                $found = true;
                break;
            }
        }
        return $not ? !$found : $found;
    }

    private function checkLike(mixed $val, string $pattern, bool $not): bool
    {
        $str = (string)($val ?? '');
        // Convert SQL LIKE pattern (% -> .*, _ -> .) to regex
        $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
        $match = (bool)preg_match($regex, $str);
        return $not ? !$match : $match;
    }

    private function compareValues(mixed $actual, string $op, mixed $expected): bool
    {
        // Handle nulls
        if ($actual === null) {
            $actual = '';
        }

        // Numeric comparison if both can be numeric
        if (is_numeric($actual) && is_numeric($expected)) {
            $numA = (float)$actual;
            $numB = (float)$expected;

            return match ($op) {
                '=' => $numA == $numB,
                '!=' => $numA != $numB,
                '>' => $numA > $numB,
                '>=' => $numA >= $numB,
                '<' => $numA < $numB,
                '<=' => $numA <= $numB,
                default => false,
            };
        }

        // Boolean values
        $lowerAct = is_bool($actual) ? ($actual ? 'true' : 'false') : strtolower((string)$actual);
        $lowerExp = strtolower((string)$expected);
        if (in_array($lowerExp, ['true', 'false'], true) && in_array($lowerAct, ['true', 'false', '1', '0'], true)) {
            $bAct = in_array($lowerAct, ['true', '1'], true);
            $bExp = $lowerExp === 'true';
            return $op === '=' ? ($bAct === $bExp) : ($bAct !== $bExp);
        }

        // String comparison
        $cmp = strcasecmp((string)$actual, (string)$expected);
        return match ($op) {
            '=' => $cmp === 0,
            '!=' => $cmp !== 0,
            '>' => $cmp > 0,
            '>=' => $cmp >= 0,
            '<' => $cmp < 0,
            '<=' => $cmp <= 0,
            default => false,
        };
    }
}
