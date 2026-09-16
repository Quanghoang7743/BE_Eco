<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseSafety;

class TestDatabaseSafetyTest extends TestCase
{
    public function test_it_allows_only_testing_sqlite_memory_databases(): void
    {
        TestDatabaseSafety::assertSafe('testing', 'sqlite', ':memory:');

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider unsafeDatabaseConfigurations
     */
    public function test_it_rejects_every_non_isolated_database(
        string $environment,
        string $driver,
        string $database,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe test database blocked');

        TestDatabaseSafety::assertSafe($environment, $driver, $database);
    }

    public static function unsafeDatabaseConfigurations(): array
    {
        return [
            'local mysql database' => ['local', 'mysql', 'ecoluck_job'],
            'testing mysql database' => ['testing', 'mysql', 'ecoluck_test'],
            'sqlite file database' => ['testing', 'sqlite', 'database/testing.sqlite'],
            'wrong app environment' => ['local', 'sqlite', ':memory:'],
        ];
    }
}
