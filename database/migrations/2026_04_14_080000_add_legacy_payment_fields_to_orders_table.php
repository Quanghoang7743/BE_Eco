<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'name')) {
                $table->string('name')->nullable()->after('id');
            }

            if (!Schema::hasColumn('orders', 'phone')) {
                $table->string('phone')->nullable()->after('name');
            }

            if (!Schema::hasColumn('orders', 'address')) {
                $table->string('address')->nullable()->after('phone');
            }

            if (!Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('address');
            }

            if (!Schema::hasColumn('orders', 'total')) {
                $table->decimal('total', 12, 0)->default(0)->after('payment_method');
            }

            if (!Schema::hasColumn('orders', 'vat_amount')) {
                $table->unsignedInteger('vat_amount')->default(0)->after('total');
            }

            if (!Schema::hasColumn('orders', 'status_payment')) {
                $table->enum('status_payment', ['pending', 'paid', 'failed', 'refunded'])
                    ->default('pending')
                    ->after('status');
            }

            if (!Schema::hasColumn('orders', 'voucher_code')) {
                $table->string('voucher_code')->nullable()->after('vat_amount');
            }

            if (!Schema::hasColumn('orders', 'referrer_id')) {
                $table->foreignId('referrer_id')
                    ->nullable()
                    ->after('voucher_code')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('orders', 'transaction_id')) {
                $table->string('transaction_id')->nullable()->after('referrer_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'transaction_id')) {
                $table->dropColumn('transaction_id');
            }

            if (Schema::hasColumn('orders', 'referrer_id')) {
                $table->dropConstrainedForeignId('referrer_id');
            }

            if (Schema::hasColumn('orders', 'voucher_code')) {
                $table->dropColumn('voucher_code');
            }

            if (Schema::hasColumn('orders', 'status_payment')) {
                $table->dropColumn('status_payment');
            }

            if (Schema::hasColumn('orders', 'vat_amount')) {
                $table->dropColumn('vat_amount');
            }

            if (Schema::hasColumn('orders', 'total')) {
                $table->dropColumn('total');
            }

            if (Schema::hasColumn('orders', 'payment_method')) {
                $table->dropColumn('payment_method');
            }

            if (Schema::hasColumn('orders', 'address')) {
                $table->dropColumn('address');
            }

            if (Schema::hasColumn('orders', 'phone')) {
                $table->dropColumn('phone');
            }

            if (Schema::hasColumn('orders', 'name')) {
                $table->dropColumn('name');
            }
        });
    }
};