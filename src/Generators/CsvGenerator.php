<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer\Generators;

/**
 * High-performance streaming CSV generator.
 */
class CsvGenerator implements GeneratorInterface
{
    /**
     * @inheritDoc
     */
    public function generate(iterable $rows, ?string $outputPath = null, array $options = []): string|int
    {
        $delimiter = $options['delimiter'] ?? ',';
        $enclosure = $options['enclosure'] ?? '"';
        $escape = $options['escape'] ?? '\\';
        $includeHeaders = $options['include_headers'] ?? true;

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
            $headersWritten = false;
            $count = 0;
            $headers = [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (!$headersWritten) {
                    $headers = array_keys($row);
                    if ($includeHeaders) {
                        fputcsv($stream, $headers, $delimiter, $enclosure, $escape);
                    }
                    $headersWritten = true;
                }

                // Align values to headers
                $line = [];
                foreach ($headers as $header) {
                    $val = $row[$header] ?? '';
                    if (is_array($val) || is_object($val)) {
                        $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } elseif (is_bool($val)) {
                        $val = $val ? '1' : '0';
                    }
                    $line[] = $val;
                }

                fputcsv($stream, $line, $delimiter, $enclosure, $escape);
                $count++;
            }

            if ($outputPath !== null) {
                return $count;
            }

            rewind($stream);
            return stream_get_contents($stream);
        } finally {
            if ($stream !== null) {
                fclose($stream);
            }
        }
    }
}
