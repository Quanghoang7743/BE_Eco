<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseSafety;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        TestDatabaseSafety::forceIsolatedEnvironment();

        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make('events')->listen(
            ConnectionEstablished::class,
            static function (ConnectionEstablished $event) use ($app): void {
                TestDatabaseSafety::assertSafe(
                    (string) $app->environment(),
                    (string) $event->connection->getDriverName(),
                    (string) $event->connection->getDatabaseName(),
                );
            },
        );

        $app->make(Kernel::class)->bootstrap();

        $defaultConnection = (string) $app->make('config')->get('database.default');

        TestDatabaseSafety::assertSafe(
            (string) $app->environment(),
            (string) $app->make('config')->get("database.connections.{$defaultConnection}.driver"),
            (string) $app->make('config')->get("database.connections.{$defaultConnection}.database"),
        );

        return $app;
    }
}
