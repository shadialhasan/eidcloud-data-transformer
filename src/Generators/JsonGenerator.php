<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer\Generators;

/**
 * Memory-efficient streaming JSON / NDJSON generator.
 */
class JsonGenerator implements GeneratorInterface
{
    /**
     * @inheritDoc
     */
    public function generate(iterable $rows, ?string $outputPath = null, array $options = []): string|int
    {
        $pretty = $options['pretty'] ?? true;
        $ndjson = $options['ndjson'] ?? false;
        $encodeFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty && !$ndjson) {
            $encodeFlags |= JSON_PRETTY_PRINT;
        }

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
            $count = 0;

            if ($ndjson) {
                foreach ($rows as $row) {
                    fwrite($stream, json_encode($row, $encodeFlags) . "\n");
                    $count++;
                }
            } else {
                // Streaming standard JSON array
                fwrite($stream, "[\n");
                $first = true;

                foreach ($rows as $row) {
                    if (!$first) {
                        fwrite($stream, ",\n");
                    }
                    $encoded = json_encode($row, $encodeFlags);
                    if ($pretty) {
                        // Indent each line by 2 spaces
                        $lines = explode("\n", (string)$encoded);
                        $indented = implode("\n", array_map(fn($line) => '  ' . $line, $lines));
                        fwrite($stream, $indented);
                    } else {
                        fwrite($stream, $encoded);
                    }
                    $first = false;
                    $count++;
                }

                fwrite($stream, "\n]\n");
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
