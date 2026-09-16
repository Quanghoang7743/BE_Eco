<?php

use App\Models\AffiliateCustomer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('source_type', 100)->nullable();
            $table->string('source_id', 191)->nullable();
            $table->string('first_order_id', 191)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->string('status', 50)->default(AffiliateCustomer::STATUS_ACTIVE);
            $table->foreignId('transferred_to_affiliate_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at')->nullable();
            $table->text('transfer_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['affiliate_id', 'status'], 'affiliate_customers_affiliate_status_idx');
            $table->index(['customer_id', 'status'], 'affiliate_customers_customer_status_idx');
            $table->index('assigned_at');
        });

        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'referrer_id')) {
            return;
        }

        DB::table('users')
            ->select(['id', 'referrer_id', 'created_at'])
            ->whereNotNull('referrer_id')
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $existingActive = DB::table('affiliate_customers')
                        ->where('customer_id', $user->id)
                        ->where('status', AffiliateCustomer::STATUS_ACTIVE)
                        ->exists();

                    if ($existingActive) {
                        continue;
                    }

                    $firstOrder = Schema::hasTable('orders')
                        ? DB::table('orders')
                            ->where('user_id', $user->id)
                            ->orderBy('created_at')
                            ->first(['id', 'created_at'])
                        : null;

                    DB::table('affiliate_customers')->insert([
                        'affiliate_id' => $user->referrer_id,
                        'customer_id' => $user->id,
                        'source_type' => 'legacy_referrer_backfill',
                        'source_id' => (string) $user->referrer_id,
                        'first_order_id' => $firstOrder?->id ?? null,
                        'assigned_at' => $firstOrder?->created_at ?? $user->created_at ?? now(),
                        'status' => AffiliateCustomer::STATUS_ACTIVE,
                        'transferred_to_affiliate_id' => null,
                        'transferred_at' => null,
                        'transfer_reason' => 'Backfilled from users.referrer_id',
                        'created_by' => null,
                        'updated_by' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_customers');
    }
};
