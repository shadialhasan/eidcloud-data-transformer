<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer\Parsers;

/**
 * Memory-efficient streaming XML parser using XMLReader.
 */
class XmlParser implements ParserInterface
{
    /**
     * @inheritDoc
     */
    public function parse(string $filepath, array $options = []): \Generator
    {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            throw new \RuntimeException("XML file does not exist or is not readable: {$filepath}");
        }

        $reader = new \XMLReader();
        if (!$reader->open($filepath)) {
            throw new \RuntimeException("Failed to open XML file with XMLReader: {$filepath}");
        }

        try {
            $recordTag = $options['record_tag'] ?? null;
            $depth = 0;
            $rootFound = false;

            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    if (!$rootFound) {
                        $rootFound = true;
                        continue;
                    }

                    // If recordTag is specified, check against it; otherwise take direct children of root (depth 1)
                    if ($recordTag !== null) {
                        if ($reader->localName === $recordTag) {
                            yield $this->parseCurrentNode($reader);
                        }
                    } else {
                        // Depth 1 inside root
                        if ($reader->depth === 1) {
                            yield $this->parseCurrentNode($reader);
                        }
                    }
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Convert the current XML element into an associative array.
     */
    private function parseCurrentNode(\XMLReader $reader): array
    {
        $xmlString = $reader->readOuterXML();
        if (empty($xmlString)) {
            return [];
        }

        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            return [];
        }

        return $this->xmlToArray($xml);
    }

    /**
     * Recursively convert SimpleXMLElement to associative array.
     */
    private function xmlToArray(\SimpleXMLElement $xml): array
    {
        $result = [];

        // Include attributes with '@' prefix or merged
        foreach ($xml->attributes() as $name => $value) {
            $result[(string)$name] = (string)$value;
        }

        // Include children
        $hasChildren = false;
        foreach ($xml->children() as $name => $child) {
            $hasChildren = true;
            $childName = (string)$name;
            if ($child->count() > 0) {
                $result[$childName] = $this->xmlToArray($child);
            } else {
                $result[$childName] = (string)$child;
            }
        }

        if (!$hasChildren && empty($result)) {
            // Self-closing or text only node
            $text = trim((string)$xml);
            return $text === '' ? [] : ['value' => $text];
        }

        return $result;
    }
}
