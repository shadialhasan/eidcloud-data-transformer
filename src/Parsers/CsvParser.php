<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer\Parsers;

/**
 * High-performance streaming CSV parser.
 */
class CsvParser implements ParserInterface
{
    /**
     * @inheritDoc
     */
    public function parse(string $filepath, array $options = []): \Generator
    {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            throw new \RuntimeException("CSV file does not exist or is not readable: {$filepath}");
        }

        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open CSV file: {$filepath}");
        }

        try {
            $delimiter = $options['delimiter'] ?? $this->detectDelimiter($filepath);
            $enclosure = $options['enclosure'] ?? '"';
            $escape = $options['escape'] ?? '\\';
            $hasHeaders = $options['headers'] ?? true;
            $trim = $options['trim'] ?? true;

            $headers = [];
            $rowNumber = 0;

            while (($rawRow = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
                $rowNumber++;

                // Skip completely empty lines
                if (count($rawRow) === 1 && ($rawRow[0] === null || $rawRow[0] === '')) {
                    continue;
                }

                if ($rowNumber === 1 && $hasHeaders) {
                    $headers = $this->sanitizeHeaders($rawRow, $trim);
                    continue;
                }

                if (!$hasHeaders && empty($headers)) {
                    $headers = array_map(fn($idx) => "col_" . ($idx + 1), array_keys($rawRow));
                }

                $record = [];
                foreach ($headers as $index => $header) {
                    $val = $rawRow[$index] ?? null;
                    if ($trim && is_string($val)) {
                        $val = trim($val);
                    }
                    $record[$header] = $val;
                }

                yield $record;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Detect CSV delimiter by analyzing the first non-empty line.
     */
    private function detectDelimiter(string $filepath): string
    {
        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            return ',';
        }

        $firstLine = '';
        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line !== false && trim($line) !== '') {
                $firstLine = $line;
                break;
            }
        }
        fclose($handle);

        if ($firstLine === '') {
            return ',';
        }

        $delimiters = [',', ';', "\t", '|'];
        $bestDelimiter = ',';
        $maxCount = 0;

        foreach ($delimiters as $del) {
            $count = substr_count($firstLine, $del);
            if ($count > $maxCount) {
                $maxCount = $count;
                $bestDelimiter = $del;
            }
        }

        return $bestDelimiter;
    }

    /**
     * Clean and normalize headers (remove BOM, trim, handle empties).
     *
     * @param array<int, string|null> $rawHeaders
     * @param bool $trim
     * @return array<int, string>
     */
    private function sanitizeHeaders(array $rawHeaders, bool $trim): array
    {
        $headers = [];
        foreach ($rawHeaders as $index => $header) {
            $h = (string)($header ?? '');
            // Strip UTF-8 BOM if present on first column
            if ($index === 0) {
                $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;
            }
            if ($trim) {
                $h = trim($h);
            }
            if ($h === '') {
                $h = 'col_' . ($index + 1);
            }
            $headers[$index] = $h;
        }
        return $headers;
    }
}
