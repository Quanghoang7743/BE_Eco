<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || Schema::hasColumn('products', 'ticket_reward_enabled')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('ticket_reward_enabled')->default(false)->after('ticket_reward_quantity');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'ticket_reward_enabled')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('ticket_reward_enabled');
        });
    }
};
