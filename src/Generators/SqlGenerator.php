<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer\Generators;

/**
 * Intelligent SQL generator with automatic type inference and batch inserts.
 */
class SqlGenerator implements GeneratorInterface
{
    /**
     * @inheritDoc
     */
    public function generate(iterable $rows, ?string $outputPath = null, array $options = []): string|int
    {
        $tableName = $this->sanitizeIdentifier($options['table'] ?? 'records');
        $createTable = $options['create_table'] ?? true;
        $batchSize = max(1, (int)($options['batch_size'] ?? 100));
        $dialect = strtolower((string)($options['dialect'] ?? 'mysql'));

        $stream = null;
        if ($outputPath !== null) {
            $dir = dirname($outputPath);
            if (!is_dir($dir) && !empty($dir)) {
                mkdir($dir, 0777, true);
            }
            $stream = fopen($outputPath, 'w');
            if ($stream === false) {
                throw new \RuntimeException("Failed to open output file for writing: {$outputPath}");
            }
        } else {
            $stream = fopen('php://temp', 'r+');
            if ($stream === false) {
                throw new \RuntimeException("Failed to open in-memory temporary stream");
            }
        }

        try {
            // Buffer rows to analyze types and perform batched generation
            $bufferedRows = [];
            $columnTypes = [];
            $columnNullable = [];
            $columnMaxLength = [];
            $allColumns = [];

            // First pass / buffering
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $bufferedRows[] = $row;

                foreach ($row as $col => $val) {
                    $safeCol = $this->sanitizeIdentifier((string)$col);
                    if (!isset($allColumns[$safeCol])) {
                        $allColumns[$safeCol] = (string)$col;
                    }

                    if ($val === null || $val === '' || (is_string($val) && strtolower($val) === 'null')) {
                        $columnNullable[$safeCol] = true;
                    }

                    $detected = $this->detectType($val);
                    $columnTypes[$safeCol] = $this->resolveType($columnTypes[$safeCol] ?? null, $detected);

                    $len = is_string($val) ? strlen($val) : strlen((string)$val);
                    $columnMaxLength[$safeCol] = max($columnMaxLength[$safeCol] ?? 0, $len);
                }
            }

            if (empty($allColumns)) {
                if ($outputPath !== null) {
                    return 0;
                }
                rewind($stream);
                return '';
            }

            // Write Header Notice
            fwrite($stream, "-- -------------------------------------------------------------\n");
            fwrite($stream, "-- EidCloud Data Transformer Generated SQL\n");
            fwrite($stream, "-- Table: {$tableName}\n");
            fwrite($stream, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($stream, "-- -------------------------------------------------------------\n\n");

            // Generate CREATE TABLE statement
            if ($createTable) {
                $createSql = $this->generateCreateTableSql(
                    $tableName,
                    $allColumns,
                    $columnTypes,
                    $columnNullable,
                    $columnMaxLength,
                    $dialect
                );
                fwrite($stream, $createSql . "\n\n");
            }

            // Generate INSERT statements in batches
            $totalRows = count($bufferedRows);
            $colNames = array_keys($allColumns);
            $quotedCols = array_map(fn($c) => $this->quoteIdentifier($c, $dialect), $colNames);
            $colsList = implode(', ', $quotedCols);

            for ($i = 0; $i < $totalRows; $i += $batchSize) {
                $batch = array_slice($bufferedRows, $i, $batchSize);
                $valuesList = [];

                foreach ($batch as $record) {
                    $rowVals = [];
                    foreach ($colNames as $col) {
                        $origKey = $allColumns[$col];
                        $val = $record[$origKey] ?? null;
                        $type = $columnTypes[$col] ?? 'VARCHAR';
                        $rowVals[] = $this->formatSqlValue($val, $type);
                    }
                    $valuesList[] = "  (" . implode(', ', $rowVals) . ")";
                }

                $insertSql = "INSERT INTO " . $this->quoteIdentifier($tableName, $dialect) . " ({$colsList}) VALUES\n"
                    . implode(",\n", $valuesList) . ";\n";

                fwrite($stream, $insertSql);
            }

            if ($outputPath !== null) {
                return $totalRows;
            }

            rewind($stream);
            return stream_get_contents($stream);
        } finally {
            if ($stream !== null) {
                fclose($stream);
            }
        }
    }

    /**
     * Auto-detect the SQL type of a single value.
     */
    public function detectType(mixed $value): string
    {
        if ($value === null || $value === '' || (is_string($value) && strtolower($value) === 'null')) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return 'BOOLEAN';
        }

        if (is_int($value)) {
            return 'INT';
        }

        if (is_float($value)) {
            return 'FLOAT';
        }

        $str = trim((string)$value);

        // Boolean check
        if (in_array(strtolower($str), ['true', 'false', 'yes', 'no'], true)) {
            return 'BOOLEAN';
        }

        // Integer check (optional leading negative sign)
        if (preg_match('/^-?\d+$/', $str)) {
            if (strlen($str) > 10 || (int)$str > 2147483647 || (int)$str < -2147483648) {
                return 'BIGINT';
            }
            return 'INT';
        }

        // Float / Decimal check
        if (preg_match('/^-?\d+\.\d+([eE][+-]?\d+)?$/', $str)) {
            return 'FLOAT';
        }

        // Timestamp / Datetime ISO 8601 or SQL standard
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $str)) {
            return 'TIMESTAMP';
        }

        // Date check
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
            return 'DATE';
        }

        // Default to VARCHAR
        return 'VARCHAR';
    }

    /**
     * Reconcile existing detected type with a newly encountered type.
     */
    public function resolveType(?string $current, string $incoming): string
    {
        if ($current === null || $current === 'NULL') {
            return $incoming;
        }

        if ($incoming === 'NULL' || $current === $incoming) {
            return $current;
        }

        // Progression rules
        // INT + BIGINT -> BIGINT
        if (($current === 'INT' && $incoming === 'BIGINT') || ($current === 'BIGINT' && $incoming === 'INT')) {
            return 'BIGINT';
        }

        // INT/BIGINT + FLOAT -> FLOAT
        if (
            (in_array($current, ['INT', 'BIGINT'], true) && $incoming === 'FLOAT') ||
            ($current === 'FLOAT' && in_array($incoming, ['INT', 'BIGINT'], true))
        ) {
            return 'FLOAT';
        }

        // DATE + TIMESTAMP -> TIMESTAMP
        if (
            ($current === 'DATE' && $incoming === 'TIMESTAMP') ||
            ($current === 'TIMESTAMP' && $incoming === 'DATE')
        ) {
            return 'TIMESTAMP';
        }

        // Anything mixed with VARCHAR becomes VARCHAR
        return 'VARCHAR';
    }

    /**
     * Generate CREATE TABLE SQL statement.
     */
    private function generateCreateTableSql(
        string $tableName,
        array $allColumns,
        array $columnTypes,
        array $columnNullable,
        array $columnMaxLength,
        string $dialect
    ): string {
        $lines = [];
        $quotedTable = $this->quoteIdentifier($tableName, $dialect);

        foreach ($allColumns as $safeCol => $origCol) {
            $type = $columnTypes[$safeCol] ?? 'VARCHAR';
            $isNullable = $columnNullable[$safeCol] ?? false;
            $maxLen = $columnMaxLength[$safeCol] ?? 0;

            $sqlType = match ($type) {
                'INT' => 'INT',
                'BIGINT' => 'BIGINT',
                'FLOAT' => 'DECIMAL(10,2)',
                'BOOLEAN' => 'TINYINT(1)',
                'TIMESTAMP' => 'TIMESTAMP',
                'DATE' => 'DATE',
                default => $maxLen > 255 ? 'TEXT' : ($maxLen > 0 ? "VARCHAR(" . min(255, max(32, (int)ceil($maxLen * 1.5))) . ")" : "VARCHAR(255)")
            };

            $nullClause = $isNullable ? 'NULL' : 'NOT NULL';
            $quotedCol = $this->quoteIdentifier($safeCol, $dialect);
            $lines[] = "  {$quotedCol} {$sqlType} {$nullClause}";
        }

        $body = implode(",\n", $lines);

        return "CREATE TABLE IF NOT EXISTS {$quotedTable} (\n{$body}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    }

    /**
     * Format and escape a value for SQL insertion.
     */
    public function formatSqlValue(mixed $value, string $detectedType): string
    {
        if ($value === null || $value === '' || (is_string($value) && strtolower($value) === 'null')) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($detectedType === 'BOOLEAN') {
            $str = strtolower(trim((string)$value));
            return in_array($str, ['1', 'true', 'yes'], true) ? '1' : '0';
        }

        if (in_array($detectedType, ['INT', 'BIGINT'], true)) {
            if (is_numeric($value)) {
                return (string)(int)$value;
            }
        }

        if ($detectedType === 'FLOAT') {
            if (is_numeric($value)) {
                return (string)(float)$value;
            }
        }

        // String, Timestamp, Date, Text or fallback
        $strVal = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
        return "'" . $this->escapeString($strVal) . "'";
    }

    /**
     * Escape single quotes and backslashes for standard SQL.
     */
    private function escapeString(string $val): string
    {
        return str_replace(
            ['\\', "\0", "\n", "\r", "'", "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "''", '\\Z'],
            $val
        );
    }

    /**
     * Sanitize identifier name for column or table.
     */
    public function sanitizeIdentifier(string $identifier): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($identifier));
        if ($clean === '' || is_numeric($clean[0])) {
            $clean = 'col_' . $clean;
        }
        return strtolower($clean);
    }

    /**
     * Quote identifier with backtick or double quote according to dialect.
     */
    private function quoteIdentifier(string $name, string $dialect): string
    {
        if ($dialect === 'postgres' || $dialect === 'sqlite') {
            return '"' . str_replace('"', '""', $name) . '"';
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
