<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit;

use PHPUnit\Framework\TestCase;

class DbSchemaTest extends TestCase
{
    /**
     * Schema elements per table, in whitelist shape.
     *
     * @return array<string, array<string, array<string, true>>>
     */
    private function schema(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/db_schema.xml');
        self::assertNotFalse($xml);

        $tables = [];
        foreach ($xml->table as $table) {
            $entry = [];
            foreach ($table->column as $column) {
                $entry['column'][(string)$column['name']] = true;
            }
            foreach ($table->index as $index) {
                $entry['index'][(string)$index['referenceId']] = true;
            }
            foreach ($table->constraint as $constraint) {
                $entry['constraint'][(string)$constraint['referenceId']] = true;
            }
            $tables[(string)$table['name']] = $entry;
        }

        return $tables;
    }

    /**
     * @return array<string, array<string, array<string, bool>>>
     */
    private function whitelist(): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/etc/db_schema_whitelist.json');
        self::assertIsString($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSchemaAndWhitelistMatchBothWays(): void
    {
        $schema = $this->schema();
        $whitelist = $this->whitelist();

        ksort($schema);
        ksort($whitelist);
        foreach ([&$schema, &$whitelist] as &$side) {
            foreach ($side as &$table) {
                ksort($table);
                foreach ($table as &$elements) {
                    ksort($elements);
                }
            }
        }
        unset($side, $table, $elements);

        self::assertSame($schema, $whitelist);
    }

    public function testExpectedTablesAndKeys(): void
    {
        $schema = $this->schema();

        self::assertSame(['dmlab_typesafe_response_cache', 'dmlab_typesafe_usage'], array_keys($schema));
        self::assertSame(
            ['cache_key', 'consumer', 'model', 'response', 'input_tokens', 'created_at'],
            array_keys($schema['dmlab_typesafe_response_cache']['column'])
        );
        self::assertArrayHasKey(
            'DMLAB_TYPESAFE_RESPONSE_CACHE_CREATED_AT',
            $schema['dmlab_typesafe_response_cache']['index']
        );
        self::assertSame(
            ['usage_id', 'period', 'consumer', 'requests', 'cache_hits', 'input_tokens', 'updated_at'],
            array_keys($schema['dmlab_typesafe_usage']['column'])
        );
        self::assertSame(
            ['PRIMARY', 'DMLAB_TYPESAFE_USAGE_PERIOD_CONSUMER'],
            array_keys($schema['dmlab_typesafe_usage']['constraint'])
        );
    }
}
