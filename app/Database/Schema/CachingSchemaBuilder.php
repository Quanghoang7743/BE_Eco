<?php

namespace App\Database\Schema;

use Illuminate\Database\Schema\MySqlBuilder;

class CachingSchemaBuilder extends MySqlBuilder
{
    /**
     * Cache of tables that have been verified to exist.
     *
     * @var array<string, bool>
     */
    protected static array $hasTableCache = [];

    /**
     * Cache of columns that have been verified to exist.
     *
     * @var array<string, bool>
     */
    protected static array $hasColumnCache = [];

    /**
     * Determine if the given table exists.
     *
     * @param  string  $table
     * @return bool
     */
    public function hasTable($table)
    {
        if (! isset(self::$hasTableCache[$table])) {
            self::$hasTableCache[$table] = parent::hasTable($table);
        }

        return self::$hasTableCache[$table];
    }

    /**
     * Determine if the given table has a given column.
     *
     * @param  string  $table
     * @param  string  $column
     * @return bool
     */
    public function hasColumn($table, $column)
    {
        $key = $table.'.'.strtolower($column);
        if (! isset(self::$hasColumnCache[$key])) {
            self::$hasColumnCache[$key] = parent::hasColumn($table, $column);
        }

        return self::$hasColumnCache[$key];
    }

    /**
     * Clear the in-memory cache.
     *
     * @return void
     */
    public static function clearCache()
    {
        self::$hasTableCache = [];
        self::$hasColumnCache = [];
    }
}
