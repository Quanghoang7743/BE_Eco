<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('bank_accounts') && ! Schema::hasColumn('bank_accounts', 'is_default')) {
            Schema::table('bank_accounts', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('bank_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('bank_accounts') && Schema::hasColumn('bank_accounts', 'is_default')) {
            Schema::table('bank_accounts', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }
};
