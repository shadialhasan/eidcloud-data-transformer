<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer\Generators;

/**
 * Memory-efficient streaming XML generator.
 */
class XmlGenerator implements GeneratorInterface
{
    /**
     * @inheritDoc
     */
    public function generate(iterable $rows, ?string $outputPath = null, array $options = []): string|int
    {
        $rootTag = $options['root_tag'] ?? 'dataset';
        $rowTag = $options['row_tag'] ?? 'record';

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
            fwrite($stream, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
            fwrite($stream, "<{$rootTag}>\n");

            $count = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                fwrite($stream, "  <{$rowTag}>\n");
                foreach ($row as $key => $value) {
                    $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$key);
                    if ($safeKey === '' || is_numeric($safeKey[0])) {
                        $safeKey = 'col_' . $safeKey;
                    }

                    if (is_array($value)) {
                        $this->writeNestedXml($stream, $safeKey, $value, 4);
                    } else {
                        $valStr = (string)($value ?? '');
                        $escapedVal = htmlspecialchars($valStr, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                        fwrite($stream, "    <{$safeKey}>{$escapedVal}</{$safeKey}>\n");
                    }
                }
                fwrite($stream, "  </{$rowTag}>\n");
                $count++;
            }

            fwrite($stream, "</{$rootTag}>\n");

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

    /**
     * Recursively write nested array nodes.
     *
     * @param resource $stream
     */
    private function writeNestedXml($stream, string $key, array $data, int $indent): void
    {
        $spaces = str_repeat(' ', $indent);
        fwrite($stream, "{$spaces}<{$key}>\n");

        foreach ($data as $subKey => $subVal) {
            $safeSubKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$subKey);
            if ($safeSubKey === '' || is_numeric($safeSubKey[0])) {
                $safeSubKey = 'item_' . $safeSubKey;
            }

            if (is_array($subVal)) {
                $this->writeNestedXml($stream, $safeSubKey, $subVal, $indent + 2);
            } else {
                $valStr = (string)($subVal ?? '');
                $escaped = htmlspecialchars($valStr, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $subSpaces = str_repeat(' ', $indent + 2);
                fwrite($stream, "{$subSpaces}<{$safeSubKey}>{$escaped}</{$safeSubKey}>\n");
            }
        }

        fwrite($stream, "{$spaces}</{$key}>\n");
    }
}
