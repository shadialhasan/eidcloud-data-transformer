<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer\Generators;

/**
 * Interface for format generators.
 */
interface GeneratorInterface
{
    /**
     * Generate target format from iterable rows.
     *
     * @param iterable<array<string, mixed>> $rows Stream of records.
     * @param string|null $outputPath Optional destination file path. If null, returns string.
     * @param array<string, mixed> $options Generator-specific options.
     * @return string|int If $outputPath is null, returns string content; otherwise returns record count.
     * @throws \RuntimeException If writing fails.
     */
    public function generate(iterable $rows, ?string $outputPath = null, array $options = []): string|int;
}
