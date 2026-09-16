<?php

use App\Models\AffiliateProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('affiliate_profiles')) {
            DB::statement("ALTER TABLE `affiliate_profiles` MODIFY `status` VARCHAR(50) NOT NULL DEFAULT 'draft'");
        }

        Schema::table('affiliate_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('affiliate_profiles', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('rejection_reason');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'review_started_at')) {
                $table->timestamp('review_started_at')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('review_started_at');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'rejected_by')) {
                $table->unsignedBigInteger('rejected_by')->nullable()->after('rejected_at');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'need_more_info_reason')) {
                $table->text('need_more_info_reason')->nullable()->after('rejected_by');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'suspended_reason')) {
                $table->text('suspended_reason')->nullable()->after('need_more_info_reason');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'terminated_reason')) {
                $table->text('terminated_reason')->nullable()->after('suspended_reason');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'affiliate_code')) {
                $table->string('affiliate_code', 64)->nullable()->unique()->after('terminated_reason');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'affiliate_link')) {
                $table->string('affiliate_link', 255)->nullable()->after('affiliate_code');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'accepted_policy')) {
                $table->boolean('accepted_policy')->default(false)->after('affiliate_link');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'accepted_no_fake_order_commitment')) {
                $table->boolean('accepted_no_fake_order_commitment')->default(false)->after('accepted_policy');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'accepted_tax_bank_accuracy_commitment')) {
                $table->boolean('accepted_tax_bank_accuracy_commitment')->default(false)->after('accepted_no_fake_order_commitment');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'accepted_communication_commitment')) {
                $table->boolean('accepted_communication_commitment')->default(false)->after('accepted_tax_bank_accuracy_commitment');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('accepted_communication_commitment');
            }
            if (! Schema::hasColumn('affiliate_profiles', 'policy_version')) {
                $table->string('policy_version', 50)->nullable()->after('accepted_at');
            }
        });

        DB::table('affiliate_profiles')
            ->where('status', 'pending')
            ->update([
                'status' => AffiliateProfile::STATUS_SUBMITTED,
                'submitted_at' => DB::raw('COALESCE(submitted_at, created_at)'),
            ]);

        DB::table('affiliate_profiles')
            ->where('status', 'approved')
            ->update([
                'approved_at' => DB::raw('COALESCE(approved_at, updated_at)'),
            ]);

        DB::table('affiliate_profiles')
            ->where('status', 'rejected')
            ->update([
                'rejected_at' => DB::raw('COALESCE(rejected_at, updated_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('affiliate_profiles', function (Blueprint $table) {
            foreach ([
                'submitted_at',
                'review_started_at',
                'approved_at',
                'approved_by',
                'rejected_at',
                'rejected_by',
                'need_more_info_reason',
                'suspended_reason',
                'terminated_reason',
                'affiliate_code',
                'affiliate_link',
                'accepted_policy',
                'accepted_no_fake_order_commitment',
                'accepted_tax_bank_accuracy_commitment',
                'accepted_communication_commitment',
                'accepted_at',
                'policy_version',
            ] as $column) {
                if (Schema::hasColumn('affiliate_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasTable('affiliate_profiles')) {
            DB::table('affiliate_profiles')
                ->whereIn('status', [
                    AffiliateProfile::STATUS_DRAFT,
                    AffiliateProfile::STATUS_SUBMITTED,
                    AffiliateProfile::STATUS_UNDER_REVIEW,
                    AffiliateProfile::STATUS_NEED_MORE_INFO,
                ])
                ->update(['status' => 'pending']);

            DB::table('affiliate_profiles')
                ->whereIn('status', [
                    AffiliateProfile::STATUS_SUSPENDED,
                    AffiliateProfile::STATUS_LOCKED,
                    AffiliateProfile::STATUS_TERMINATED,
                ])
                ->update(['status' => 'rejected']);

            DB::statement("ALTER TABLE `affiliate_profiles` MODIFY `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }
    }
};
