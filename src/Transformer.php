<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer;

use EidCloud\DataTransformer\Parsers\CsvParser;
use EidCloud\DataTransformer\Parsers\JsonParser;
use EidCloud\DataTransformer\Parsers\XmlParser;
use EidCloud\DataTransformer\Parsers\ParserInterface;
use EidCloud\DataTransformer\Generators\CsvGenerator;
use EidCloud\DataTransformer\Generators\JsonGenerator;
use EidCloud\DataTransformer\Generators\XmlGenerator;
use EidCloud\DataTransformer\Generators\SqlGenerator;
use EidCloud\DataTransformer\Generators\GeneratorInterface;
use EidCloud\DataTransformer\Query\QueryEngine;

/**
 * Universal Data Transformer & Query Engine.
 */
class Transformer
{
    private ?string $inputFile = null;
    private ?string $inputFormat = null;
    private ?string $select = null;
    private ?string $where = null;
    private ?string $sort = null;
    private ?int $limit = null;
    private int $offset = 0;
    private array $parserOptions = [];
    private array $generatorOptions = [];

    /**
     * Set input file and optional format override.
     */
    public function from(string $filepath, ?string $format = null, array $options = []): self
    {
        $this->inputFile = $filepath;
        $this->inputFormat = $format ?? $this->detectFormat($filepath);
        $this->parserOptions = $options;
        return $this;
    }

    /**
     * Configure SELECT columns.
     */
    public function select(string|array $columns): self
    {
        $this->select = is_array($columns) ? implode(', ', $columns) : $columns;
        return $this;
    }

    /**
     * Configure WHERE clause.
     */
    public function where(string $condition): self
    {
        $this->where = $condition;
        return $this;
    }

    /**
     * Configure SORT rules.
     */
    public function sort(string|array $rules): self
    {
        $this->sort = is_array($rules) ? implode(', ', $rules) : $rules;
        return $this;
    }

    /**
     * Configure LIMIT and OFFSET.
     */
    public function limit(?int $limit, int $offset = 0): self
    {
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }

    /**
     * Execute transformation to target output file or format.
     *
     * @param string $outputPath File path or format identifier (e.g. 'json', 'csv', 'sql', 'xml').
     * @param array $options Generator options (e.g. ['table' => 'users']).
     * @return array{status: string, input: string, output: string|null, format: string, records_processed: int, elapsed_time_ms: float, memory_peak_mb: float, content?: string}
     */
    public function to(string $outputPath, array $options = []): array
    {
        if ($this->inputFile === null) {
            throw new \RuntimeException("No input file specified. Call from() before to().");
        }

        $mergedOptions = array_merge($this->generatorOptions, $options);

        // Check if $outputPath is a format keyword or actual file path
        $isFormatKeyword = in_array(strtolower($outputPath), ['csv', 'json', 'sql', 'xml', 'ndjson'], true);
        $targetFormat = $isFormatKeyword ? strtolower($outputPath) : $this->detectFormat($outputPath);
        $targetFile = $isFormatKeyword ? null : $outputPath;

        return $this->execute($this->inputFile, $targetFormat, $targetFile, $mergedOptions);
    }

    /**
     * Direct conversion helper.
     *
     * @param string $inputFile Source file path.
     * @param string $targetFormat Target format ('json', 'csv', 'sql', 'xml').
     * @param array $options Options including 'output', 'select', 'where', 'sort', 'limit', 'offset', 'table', etc.
     * @return array Performance metrics and execution summary.
     */
    public function convert(string $inputFile, string $targetFormat, array $options = []): array
    {
        $this->from($inputFile, $options['from_format'] ?? null, $options['parser_options'] ?? []);

        if (isset($options['select'])) {
            $this->select($options['select']);
        }
        if (isset($options['where'])) {
            $this->where($options['where']);
        }
        if (isset($options['sort'])) {
            $this->sort($options['sort']);
        }
        if (isset($options['limit'])) {
            $this->limit((int)$options['limit'], (int)($options['offset'] ?? 0));
        }

        $targetFile = $options['output'] ?? null;
        return $this->execute($inputFile, strtolower($targetFormat), $targetFile, $options);
    }

    /**
     * Internal execution pipeline.
     */
    private function execute(string $inputFile, string $targetFormat, ?string $targetFile, array $options): array
    {
        $startTime = microtime(true);
        $startMem = memory_get_peak_usage(true);

        $parser = $this->createParser($this->inputFormat ?? $this->detectFormat($inputFile));
        $generator = $this->createGenerator($targetFormat);

        // Build streaming rows
        $rawRows = $parser->parse($inputFile, $this->parserOptions);

        // Apply embedded SQL query engine (filtering, projecting, sorting, limit)
        $queryEngine = new QueryEngine($this->select, $this->where, $this->sort, $this->limit, $this->offset);
        $processedRows = $queryEngine->apply($rawRows);

        // If target file not specified and generator needs table name, infer from input filename
        if (!isset($options['table'])) {
            $options['table'] = pathinfo($inputFile, PATHINFO_FILENAME);
        }

        // Run generator
        $genResult = $generator->generate($processedRows, $targetFile, $options);

        $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);
        $peakMemMb = round(memory_get_peak_usage(true) / (1024 * 1024), 2);

        $recordsCount = is_int($genResult) ? $genResult : null;
        $content = is_string($genResult) ? $genResult : null;

        $response = [
            'status' => 'success',
            'input' => $inputFile,
            'output' => $targetFile,
            'format' => $targetFormat,
            'records_processed' => $recordsCount ?? ($content !== null ? substr_count($content, "\n") : 0),
            'elapsed_time_ms' => $elapsedMs,
            'memory_peak_mb' => $peakMemMb,
        ];

        if ($content !== null) {
            $response['content'] = $content;
        }

        return $response;
    }

    /**
     * Create appropriate parser based on format.
     */
    public function createParser(string $format): ParserInterface
    {
        return match (strtolower($format)) {
            'csv' => new CsvParser(),
            'json', 'jsonl', 'ndjson' => new JsonParser(),
            'xml' => new XmlParser(),
            default => throw new \InvalidArgumentException("Unsupported input format: '{$format}'"),
        };
    }

    /**
     * Create appropriate generator based on format.
     */
    public function createGenerator(string $format): GeneratorInterface
    {
        return match (strtolower($format)) {
            'csv' => new CsvGenerator(),
            'json', 'jsonl', 'ndjson' => new JsonGenerator(),
            'xml' => new XmlGenerator(),
            'sql' => new SqlGenerator(),
            default => throw new \InvalidArgumentException("Unsupported output format: '{$format}'"),
        };
    }

    /**
     * Auto-detect format from file extension.
     */
    public function detectFormat(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'csv' => 'csv',
            'json' => 'json',
            'jsonl', 'ndjson' => 'json',
            'xml' => 'xml',
            'sql' => 'sql',
            default => 'csv',
        };
    }
}
