<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('permission.table_names.roles', 'spatie_roles');

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            if (! Schema::hasColumn($table->getTable(), 'display_name')) {
                $table->string('display_name')->nullable()->after('name');
            }

            if (! Schema::hasColumn($table->getTable(), 'description')) {
                $table->string('description', 500)->nullable()->after('display_name');
            }

            if (! Schema::hasColumn($table->getTable(), 'scope')) {
                $table->string('scope', 150)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        $table = config('permission.table_names.roles', 'spatie_roles');

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            if (Schema::hasColumn($table->getTable(), 'scope')) {
                $table->dropColumn('scope');
            }

            if (Schema::hasColumn($table->getTable(), 'description')) {
                $table->dropColumn('description');
            }

            if (Schema::hasColumn($table->getTable(), 'display_name')) {
                $table->dropColumn('display_name');
            }
        });
    }
};
