<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer\Parsers;

/**
 * Memory-efficient streaming JSON / NDJSON parser.
 */
class JsonParser implements ParserInterface
{
    /**
     * @inheritDoc
     */
    public function parse(string $filepath, array $options = []): \Generator
    {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            throw new \RuntimeException("JSON file does not exist or is not readable: {$filepath}");
        }

        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open JSON file: {$filepath}");
        }

        try {
            // Peek at the first non-whitespace character
            $firstChar = '';
            while (!feof($handle)) {
                $char = fgetc($handle);
                if ($char !== false && trim($char) !== '') {
                    $firstChar = $char;
                    break;
                }
            }
            rewind($handle);

            if ($firstChar === '[') {
                // Standard JSON array of objects - streaming token/chunk extractor
                yield from $this->streamJsonArray($handle);
            } elseif ($firstChar === '{') {
                // Could be NDJSON (lines of { ... }) or a single JSON object containing a list
                yield from $this->streamObjectOrNdjson($handle);
            } else {
                // Fallback to reading and decoding
                yield from $this->fallbackParse($filepath);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Stream array of JSON objects without loading the entire file into memory.
     *
     * @param resource $handle
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamJsonArray($handle): \Generator
    {
        $buffer = '';
        $inString = false;
        $escape = false;
        $depth = 0;
        $insideArray = false;
        $currentObject = '';

        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $len = strlen($chunk);
            for ($i = 0; $i < $len; $i++) {
                $char = $chunk[$i];

                if (!$insideArray) {
                    if ($char === '[') {
                        $insideArray = true;
                    }
                    continue;
                }

                if ($escape) {
                    $escape = false;
                    if ($depth > 0) {
                        $currentObject .= $char;
                    }
                    continue;
                }

                if ($char === '\\' && $inString) {
                    $escape = true;
                    if ($depth > 0) {
                        $currentObject .= $char;
                    }
                    continue;
                }

                if ($char === '"') {
                    $inString = !$inString;
                    if ($depth > 0) {
                        $currentObject .= $char;
                    }
                    continue;
                }

                if (!$inString) {
                    if ($char === '{') {
                        $depth++;
                        $currentObject .= $char;
                        continue;
                    }

                    if ($char === '}') {
                        $depth--;
                        $currentObject .= $char;

                        if ($depth === 0) {
                            $decoded = json_decode($currentObject, true);
                            if (is_array($decoded)) {
                                yield $decoded;
                            }
                            $currentObject = '';
                        }
                        continue;
                    }

                    if ($depth === 0 && $char === ']') {
                        // End of main array
                        return;
                    }
                }

                if ($depth > 0) {
                    $currentObject .= $char;
                }
            }
        }
    }

    /**
     * Stream newline-delimited JSON or check if root object has an embedded collection.
     *
     * @param resource $handle
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamObjectOrNdjson($handle): \Generator
    {
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            return;
        }

        $decodedLine = json_decode(trim($firstLine), true);
        if (is_array($decodedLine)) {
            // Check if subsequent line is also valid JSON (NDJSON format)
            $secondLine = fgets($handle);
            if ($secondLine !== false && trim($secondLine) !== '') {
                $secondDecoded = json_decode(trim($secondLine), true);
                if (is_array($secondDecoded)) {
                    // It's NDJSON!
                    yield $decodedLine;
                    yield $secondDecoded;
                    while (!feof($handle)) {
                        $line = fgets($handle);
                        if ($line === false) {
                            break;
                        }
                        $trimmed = trim($line);
                        if ($trimmed === '') {
                            continue;
                        }
                        $row = json_decode($trimmed, true);
                        if (is_array($row)) {
                            yield $row;
                        }
                    }
                    return;
                }
            }

            // Not NDJSON: rewind and check if single root object wraps an array or is a single record
            rewind($handle);
            $fullContent = stream_get_contents($handle);
            $root = json_decode((string)$fullContent, true);
            if (is_array($root)) {
                // If it contains a list of records under keys like 'data', 'items', 'users', etc.
                foreach ($root as $val) {
                    if (is_array($val) && isset($val[0]) && is_array($val[0])) {
                        foreach ($val as $subItem) {
                            if (is_array($subItem)) {
                                yield $subItem;
                            }
                        }
                        return;
                    }
                }
                // Otherwise yield the root object as a single record
                yield $root;
            }
        }
    }

    /**
     * Fallback standard parse for unusual structures.
     *
     * @param string $filepath
     * @return \Generator<int, array<string, mixed>>
     */
    private function fallbackParse(string $filepath): \Generator
    {
        $content = file_get_contents($filepath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$filepath}");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in {$filepath}: " . json_last_error_msg());
        }

        if (is_array($data)) {
            if (isset($data[0]) && is_array($data[0])) {
                foreach ($data as $item) {
                    if (is_array($item)) {
                        yield $item;
                    }
                }
            } else {
                yield $data;
            }
        }
    }
}
