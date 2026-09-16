<?php

namespace Tests\Unit;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\SQLiteConnection;
use PDO;
use RuntimeException;
use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_test_bootstrap_forces_an_in_memory_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', config('database.connections.sqlite.driver'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_connection_listener_rejects_a_persistent_database(): void
    {
        $connection = new SQLiteConnection(
            new PDO('sqlite::memory:'),
            'database/testing.sqlite',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe test database blocked');

        event(new ConnectionEstablished($connection));
    }
}
