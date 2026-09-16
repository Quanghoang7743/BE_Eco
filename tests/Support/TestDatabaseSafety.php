<?php

namespace Tests\Support;

use RuntimeException;

final class TestDatabaseSafety
{
    private const REQUIRED_ENVIRONMENT = 'testing';
    private const REQUIRED_DRIVER = 'sqlite';
    private const REQUIRED_DATABASE = ':memory:';

    public static function forceIsolatedEnvironment(): void
    {
        foreach ([
            'APP_ENV' => self::REQUIRED_ENVIRONMENT,
            'DB_CONNECTION' => self::REQUIRED_DRIVER,
            'DB_DATABASE' => self::REQUIRED_DATABASE,
        ] as $name => $value) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    public static function assertSafe(string $environment, string $driver, string $database): void
    {
        if (
            $environment !== self::REQUIRED_ENVIRONMENT
            || $driver !== self::REQUIRED_DRIVER
            || $database !== self::REQUIRED_DATABASE
        ) {
            throw new RuntimeException(sprintf(
                'Unsafe test database blocked: environment=%s, driver=%s, database=%s. '
                . 'Tests may only use testing + sqlite + :memory:.',
                $environment,
                $driver,
                $database,
            ));
        }
    }
}
