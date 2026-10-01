<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer\Parsers;

/**
 * Interface for streamable dataset parsers.
 */
interface ParserInterface
{
    /**
     * Stream records from the given file path as associative arrays.
     *
     * @param string $filepath Path to the source file.
     * @param array<string, mixed> $options Parser-specific options.
     * @return \Generator<int, array<string, mixed>>
     * @throws \RuntimeException If file cannot be read or parsed.
     */
    public function parse(string $filepath, array $options = []): \Generator;
}
