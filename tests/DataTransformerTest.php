<?php

declare(strict_types=1);

namespace EidCloud\DataTransformer\Tests;

use EidCloud\DataTransformer\Transformer;
use EidCloud\DataTransformer\Parsers\CsvParser;
use EidCloud\DataTransformer\Parsers\JsonParser;
use EidCloud\DataTransformer\Parsers\XmlParser;
use EidCloud\DataTransformer\Generators\CsvGenerator;
use EidCloud\DataTransformer\Generators\JsonGenerator;
use EidCloud\DataTransformer\Generators\XmlGenerator;
use EidCloud\DataTransformer\Generators\SqlGenerator;
use EidCloud\DataTransformer\Query\QueryEngine;

/**
 * Comprehensive test suite for EidCloud Data Transformer.
 */
class DataTransformerTest
{
    private string $tempDir;
    private int $assertionsCount = 0;

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir() . '/eidcloud_test_' . uniqid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    public function __destruct()
    {
        $this->cleanupDir($this->tempDir);
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->cleanupDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function getAssertionsCount(): int
    {
        return $this->assertionsCount;
    }

    private function assert(bool $condition, string $message = ''): void
    {
        $this->assertionsCount++;
        if (!$condition) {
            $msg = $message ?: "Assertion failed";
            throw new \AssertionError("FAILED: {$msg}");
        }
    }

    private function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertionsCount++;
        if ($expected !== $actual) {
            $msg = $message ?: ("Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
            throw new \AssertionError("FAILED: {$msg}");
        }
    }

    /**
     * Test 1: CSV -> JSON Conversion
     */
    public function testCsvToJson(): void
    {
        $csvPath = $this->tempDir . '/users.csv';
        file_put_contents($csvPath, "id,name,email,age,status\n1,Alice,alice@example.com,28,active\n2,Bob,bob@example.com,35,inactive\n3,Charlie,charlie@example.com,22,active\n");

        $transformer = new Transformer();
        $outPath = $this->tempDir . '/users.json';
        $res = $transformer->from($csvPath)->to($outPath);

        $this->assertEquals(3, $res['records_processed'], "CSV to JSON record count should be 3");
        $this->assert(file_exists($outPath), "Target JSON file must exist");

        $jsonContent = file_get_contents($outPath);
        $decoded = json_decode($jsonContent, true);
        $this->assert(is_array($decoded) && count($decoded) === 3, "Decoded JSON should have 3 items");
        $this->assertEquals("Alice", $decoded[0]['name']);
        $this->assertEquals("active", $decoded[2]['status']);
    }

    /**
     * Test 2: CSV -> SQL Generation with auto type inference
     */
    public function testCsvToSql(): void
    {
        $csvPath = $this->tempDir . '/customers.csv';
        file_put_contents($csvPath, "id,full_name,balance,is_verified,joined_at\n101,John Doe,1250.75,true,2026-01-15 10:30:00\n102,Jane Smith,450.00,false,2026-02-20 14:15:00\n");

        $transformer = new Transformer();
        $outPath = $this->tempDir . '/customers.sql';
        $res = $transformer->from($csvPath)->to($outPath, ['table' => 'customers']);

        $this->assertEquals(2, $res['records_processed'], "CSV to SQL should process 2 records");
        $sql = file_get_contents($outPath);

        $this->assert(str_contains($sql, "CREATE TABLE IF NOT EXISTS `customers`"), "SQL should contain CREATE TABLE");
        $this->assert(str_contains($sql, "`id` INT"), "ID column should be inferred as INT");
        $this->assert(str_contains($sql, "`balance` DECIMAL(10,2)"), "Balance column should be inferred as FLOAT/DECIMAL");
        $this->assert(str_contains($sql, "`is_verified` TINYINT(1)"), "is_verified column should be inferred as BOOLEAN");
        $this->assert(str_contains($sql, "`joined_at` TIMESTAMP"), "joined_at column should be inferred as TIMESTAMP");
        $this->assert(str_contains($sql, "INSERT INTO `customers`"), "SQL should contain INSERT statement");
        $this->assert(str_contains($sql, "'John Doe'"), "SQL should contain John Doe quoted string");
    }

    /**
     * Test 3: CSV -> XML Conversion
     */
    public function testCsvToXml(): void
    {
        $csvPath = $this->tempDir . '/items.csv';
        file_put_contents($csvPath, "sku,title,price\nA1,Laptop & Monitor,999.99\nB2,Keyboard <Wireless>,49.50\n");

        $transformer = new Transformer();
        $outPath = $this->tempDir . '/items.xml';
        $res = $transformer->from($csvPath)->to($outPath, ['root_tag' => 'products', 'row_tag' => 'product']);

        $this->assertEquals(2, $res['records_processed'], "CSV to XML should process 2 records");
        $xmlContent = file_get_contents($outPath);

        $this->assert(str_contains($xmlContent, "<products>"), "Root element should be products");
        $this->assert(str_contains($xmlContent, "<product>"), "Row element should be product");
        $this->assert(str_contains($xmlContent, "Laptop &amp; Monitor"), "XML entities must be escaped");
        $this->assert(str_contains($xmlContent, "Keyboard &lt;Wireless&gt;"), "XML entities must be escaped");
    }

    /**
     * Test 4: JSON -> CSV Conversion
     */
    public function testJsonToCsv(): void
    {
        $jsonPath = $this->tempDir . '/data.json';
        $data = [
            ['id' => 1, 'username' => 'satan', 'role' => 'admin'],
            ['id' => 2, 'username' => 'guest', 'role' => 'viewer'],
        ];
        file_put_contents($jsonPath, json_encode($data));

        $transformer = new Transformer();
        $outPath = $this->tempDir . '/data.csv';
        $res = $transformer->from($jsonPath)->to($outPath);

        $this->assertEquals(2, $res['records_processed'], "JSON to CSV should process 2 records");
        $csvContent = file_get_contents($outPath);

        $lines = explode("\n", trim($csvContent));
        $this->assertEquals(3, count($lines), "CSV should have header and 2 rows");
        $this->assertEquals("id,username,role", trim($lines[0]));
        $this->assert(str_contains($lines[1], "satan,admin"));
    }

    /**
     * Test 5: JSON -> SQL Conversion
     */
    public function testJsonToSql(): void
    {
        $jsonPath = $this->tempDir . '/products.json';
        $data = [
            ['product_id' => 501, 'name' => "O'Connor Widget", 'price' => 19.99, 'stock' => 150],
            ['product_id' => 502, 'name' => 'Gadget "Pro"', 'price' => 89.50, 'stock' => 45],
        ];
        file_put_contents($jsonPath, json_encode($data));

        $transformer = new Transformer();
        $outPath = $this->tempDir . '/products.sql';
        $res = $transformer->from($jsonPath)->to($outPath, ['table' => 'products']);

        $this->assertEquals(2, $res['records_processed'], "JSON to SQL should process 2 records");
        $sql = file_get_contents($outPath);

        $this->assert(str_contains($sql, "CREATE TABLE IF NOT EXISTS `products`"), "CREATE TABLE must be generated");
        $this->assert(str_contains($sql, "O''Connor Widget"), "Quotes in SQL values must be safely escaped");
        $this->assert(str_contains($sql, 'Gadget "Pro"'), "Double quotes in SQL values must be preserved");
    }

    /**
     * Test 6: XML -> JSON and XML -> CSV Conversion
     */
    public function testXmlToJsonAndCsv(): void
    {
        $xmlPath = $this->tempDir . '/inventory.xml';
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
        <inventory>
            <item>
                <id>10</id>
                <name>Widget Alpha</name>
                <qty>100</qty>
            </item>
            <item>
                <id>20</id>
                <name>Widget Beta</name>
                <qty>250</qty>
            </item>
        </inventory>";
        file_put_contents($xmlPath, $xml);

        $transformer = new Transformer();

        // XML to JSON
        $jsonOut = $this->tempDir . '/inventory.json';
        $resJson = $transformer->from($xmlPath)->to($jsonOut);
        $this->assertEquals(2, $resJson['records_processed'], "XML to JSON should process 2 records");
        $items = json_decode(file_get_contents($jsonOut), true);
        $this->assertEquals("Widget Alpha", $items[0]['name']);

        // XML to CSV
        $csvOut = $this->tempDir . '/inventory.csv';
        $resCsv = $transformer->from($xmlPath)->to($csvOut);
        $this->assertEquals(2, $resCsv['records_processed'], "XML to CSV should process 2 records");
        $this->assert(str_contains(file_get_contents($csvOut), "Widget Beta"));
    }

    /**
     * Test 7: Query Engine - WHERE Clause (Equality, Inequality, >, <, AND, OR, LIKE, IN)
     */
    public function testQueryEngineWhereFiltering(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Alice', 'role' => 'admin', 'age' => 28, 'score' => 95.5, 'country' => 'SY'],
            ['id' => 2, 'name' => 'Bob', 'role' => 'editor', 'age' => 34, 'score' => 82.0, 'country' => 'US'],
            ['id' => 3, 'name' => 'Charlie', 'role' => 'viewer', 'age' => 19, 'score' => 60.0, 'country' => 'DE'],
            ['id' => 4, 'name' => 'Diana', 'role' => 'admin', 'age' => 42, 'score' => 99.0, 'country' => 'SY'],
            ['id' => 5, 'name' => 'Evan', 'role' => 'guest', 'age' => 25, 'score' => 70.0, 'country' => 'FR'],
        ];

        // Filter 1: age > 25 AND role = 'admin'
        $qe1 = new QueryEngine(where: "age > 25 and role = 'admin'");
        $res1 = iterator_to_array($qe1->apply($rows));
        $this->assertEquals(2, count($res1), "Should match Alice and Diana");
        $this->assertEquals('Alice', $res1[0]['name']);
        $this->assertEquals('Diana', $res1[1]['name']);

        // Filter 2: role IN ('editor', 'guest')
        $qe2 = new QueryEngine(where: "role in ('editor', 'guest')");
        $res2 = iterator_to_array($qe2->apply($rows));
        $this->assertEquals(2, count($res2), "Should match Bob and Evan");

        // Filter 3: name LIKE 'C%' or age <= 20
        $qe3 = new QueryEngine(where: "name like 'C%' or age <= 20");
        $res3 = iterator_to_array($qe3->apply($rows));
        $this->assertEquals(1, count($res3), "Should match Charlie");
        $this->assertEquals('Charlie', $res3[0]['name']);

        // Filter 4: score >= 90 and (country = 'SY' or country = 'US')
        $qe4 = new QueryEngine(where: "score >= 90 and (country = 'SY' or country = 'US')");
        $res4 = iterator_to_array($qe4->apply($rows));
        $this->assertEquals(2, count($res4), "Should match Alice and Diana");
    }

    /**
     * Test 8: Query Engine - SELECT Projections and Aliasing
     */
    public function testQueryEngineSelectAndAliases(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@eidcloud.com', 'secret' => '12345'],
            ['id' => 2, 'name' => 'Bob', 'email' => 'bob@eidcloud.com', 'secret' => '67890'],
        ];

        $qe = new QueryEngine(select: "id, name AS full_name, email");
        $res = iterator_to_array($qe->apply($rows));

        $this->assertEquals(2, count($res));
        $this->assertEquals(['id' => 1, 'full_name' => 'Alice', 'email' => 'alice@eidcloud.com'], $res[0]);
        $this->assert(!isset($res[0]['secret']), "Secret column must be excluded by projection");
    }

    /**
     * Test 9: Query Engine - Sorting, Limit and Offset
     */
    public function testQueryEngineSortLimitOffset(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Charlie', 'age' => 40],
            ['id' => 2, 'name' => 'Alice', 'age' => 25],
            ['id' => 3, 'name' => 'Bob', 'age' => 30],
            ['id' => 4, 'name' => 'David', 'age' => 20],
        ];

        // Sort by age DESC with limit 2
        $qe1 = new QueryEngine(sort: "age:desc", limit: 2);
        $res1 = iterator_to_array($qe1->apply($rows));
        $this->assertEquals(2, count($res1));
        $this->assertEquals(40, $res1[0]['age']); // Charlie
        $this->assertEquals(30, $res1[1]['age']); // Bob

        // Sort by name ASC with offset 1 and limit 2
        $qe2 = new QueryEngine(sort: "name:asc", limit: 2, offset: 1);
        $res2 = iterator_to_array($qe2->apply($rows));
        // Alphabetical: Alice(0), Bob(1), Charlie(2), David(3) -> offset 1 limit 2: Bob, Charlie
        $this->assertEquals(2, count($res2));
        $this->assertEquals("Bob", $res2[0]['name']);
        $this->assertEquals("Charlie", $res2[1]['name']);
    }

    /**
     * Test 10: SQL Generator Type Inference Details
     */
    public function testSqlTypeInference(): void
    {
        $sqlGen = new SqlGenerator();

        $this->assertEquals('INT', $sqlGen->detectType(42));
        $this->assertEquals('INT', $sqlGen->detectType('100'));
        $this->assertEquals('BIGINT', $sqlGen->detectType('999999999999'));
        $this->assertEquals('FLOAT', $sqlGen->detectType(3.14159));
        $this->assertEquals('FLOAT', $sqlGen->detectType('45.99'));
        $this->assertEquals('BOOLEAN', $sqlGen->detectType(true));
        $this->assertEquals('BOOLEAN', $sqlGen->detectType('true'));
        $this->assertEquals('BOOLEAN', $sqlGen->detectType('false'));
        $this->assertEquals('TIMESTAMP', $sqlGen->detectType('2026-10-01 12:30:45'));
        $this->assertEquals('TIMESTAMP', $sqlGen->detectType('2026-10-01T12:30:45Z'));
        $this->assertEquals('DATE', $sqlGen->detectType('2026-10-01'));
        $this->assertEquals('VARCHAR', $sqlGen->detectType('Software Engineer'));
        $this->assertEquals('NULL', $sqlGen->detectType(null));
        $this->assertEquals('NULL', $sqlGen->detectType(''));

        // Type reconciliation
        $this->assertEquals('BIGINT', $sqlGen->resolveType('INT', 'BIGINT'));
        $this->assertEquals('FLOAT', $sqlGen->resolveType('INT', 'FLOAT'));
        $this->assertEquals('TIMESTAMP', $sqlGen->resolveType('DATE', 'TIMESTAMP'));
        $this->assertEquals('VARCHAR', $sqlGen->resolveType('INT', 'VARCHAR'));
    }

    /**
     * Test 11: CLI Execution & --json summary output
     */
    public function testCliExecution(): void
    {
        $csvPath = $this->tempDir . '/cli_test.csv';
        file_put_contents($csvPath, "id,username,score\n1,alpha,88\n2,beta,92\n3,gamma,79\n");

        $binPath = realpath(__DIR__ . '/../bin/eidcloud-data');
        $this->assert($binPath !== false && file_exists($binPath), "CLI binary must exist");

        // Test CLI convert to JSON with --json machine-readable output
        $cmd = "php " . escapeshellarg($binPath) . " convert " . escapeshellarg($csvPath) . " --to=json --json --where=\"score >= 80\"";
        exec($cmd, $outputLines, $returnCode);

        $this->assertEquals(0, $returnCode, "CLI execution must succeed with return code 0");
        $rawOutput = implode("\n", $outputLines);
        $summary = json_decode($rawOutput, true);

        $this->assert(is_array($summary), "CLI --json must return valid JSON output");
        $this->assertEquals('success', $summary['status']);
        $this->assertEquals(2, $summary['records_processed'], "Score >= 80 should filter to 2 records");
        $this->assertEquals('json', $summary['format']);
    }

    /**
     * Run all test cases.
     */
    public function runAll(): array
    {
        $methods = get_class_methods($this);
        $testMethods = array_filter($methods, fn($m) => str_starts_with($m, 'test'));

        $results = [
            'total' => count($testMethods),
            'passed' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        foreach ($testMethods as $test) {
            try {
                $this->$test();
                $results['passed']++;
                echo "  \033[1;32m✔ PASS\033[0m {$test}\n";
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['failures'][$test] = $e->getMessage();
                echo "  \033[1;31m✘ FAIL\033[0m {$test}: {$e->getMessage()}\n";
            }
        }

        return $results;
    }
}
