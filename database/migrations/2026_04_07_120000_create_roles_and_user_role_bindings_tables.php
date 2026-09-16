<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('user_role_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('entity_type', 50);
            $table->string('entity_id', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'entity_type', 'entity_id'], 'idx_urb_user_context');
            $table->index(['role_id', 'entity_type', 'entity_id'], 'idx_urb_role_context');
            $table->unique(['user_id', 'role_id', 'entity_type', 'entity_id'], 'uq_urb_unique_binding');
        });

        DB::table('roles')->insert([
            ['name' => 'user', 'description' => 'End user access', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'admin_store', 'description' => 'Store admin access in store context', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'super_admin', 'description' => 'Global system administration', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_bindings');
        Schema::dropIfExists('roles');
    }
};
