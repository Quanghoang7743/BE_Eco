<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Create otp_verifications table ───────────────────────────────
        if (! Schema::hasTable('otp_verifications')) {
            Schema::create('otp_verifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('admin_id')->index();
                $table->string('context', 80)->index(); // e.g. 'manual_topup:{store_id}'
                $table->string('token', 10);            // plaintext OTP shown to admin
                $table->string('token_hash', 255);      // bcrypt hash for verification
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamps();

                $table->index(['admin_id', 'context']);
            });
        }

        // ─── 2. Seed system_config defaults for topup security ───────────────
        $defaults = [
            [
                'config_key'   => 'manual_topup_access_code',
                'config_value' => Hash::make('123456'),   // default access code "123456" – change after install
                'value_type'   => 'bcrypt',
                'description'  => 'Mã khóa truy cập chức năng nạp hạn mức thủ công (bcrypt hash)',
            ],
            [
                'config_key'   => 'otp_expiry_minutes',
                'config_value' => '5',
                'value_type'   => 'integer',
                'description'  => 'Thời gian hết hạn OTP (phút)',
            ],
            [
                'config_key'   => 'otp_length',
                'config_value' => '6',
                'value_type'   => 'integer',
                'description'  => 'Độ dài mã OTP',
            ],
        ];

        foreach ($defaults as $row) {
            $exists = DB::table('system_configs')
                ->where('config_key', $row['config_key'])
                ->exists();

            if (! $exists) {
                DB::table('system_configs')->insert(array_merge($row, [
                    'updated_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_verifications');

        DB::table('system_configs')
            ->whereIn('config_key', [
                'manual_topup_access_code',
                'otp_expiry_minutes',
                'otp_length',
            ])
            ->delete();
    }
};
