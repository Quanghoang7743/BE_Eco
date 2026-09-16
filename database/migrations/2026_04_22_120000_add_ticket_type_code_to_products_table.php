<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('products') || Schema::hasColumn('products', 'ticket_type_code')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('ticket_type_code', 50)->nullable()->after('ticket_reward_quantity');
            $table->index('ticket_type_code');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'ticket_type_code')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['ticket_type_code']);
            $table->dropColumn('ticket_type_code');
        });
    }
};
