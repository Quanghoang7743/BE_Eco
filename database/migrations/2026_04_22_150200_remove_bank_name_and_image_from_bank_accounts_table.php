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
        Schema::table('bank_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('bank_accounts', 'bank_name')) {
                $table->dropColumn('bank_name');
            }
            if (Schema::hasColumn('bank_accounts', 'image')) {
                $table->dropColumn('image');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('bank_accounts', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('account_number');
            }
            if (! Schema::hasColumn('bank_accounts', 'image')) {
                $table->string('image')->nullable()->after('bank_id');
            }
        });
    }
};
