<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable()->after('full_name');
            }
            if (!Schema::hasColumn('users', 'gender')) {
                $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('bio');
            }
            if (!Schema::hasColumn('users', 'birthday')) {
                $table->date('birthday')->nullable()->after('gender');
            }
            if (!Schema::hasColumn('users', 'personal_full_name')) {
                $table->string('personal_full_name', 255)->nullable()->after('address');
            }
            if (!Schema::hasColumn('users', 'personal_identity_number')) {
                $table->string('personal_identity_number', 50)->nullable()->after('personal_full_name');
            }
            if (!Schema::hasColumn('users', 'personal_address')) {
                $table->string('personal_address', 255)->nullable()->after('personal_identity_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'bio',
                'gender',
                'birthday',
                'personal_full_name',
                'personal_identity_number',
                'personal_address',
            ]);
        });
    }
};
