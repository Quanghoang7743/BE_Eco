<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $hasTypeColumn = Schema::hasColumn('notifications', 'type');
        $hasAudienceScopeColumn = Schema::hasColumn('notifications', 'audience_scope');
        $hasUserIdColumn = Schema::hasColumn('notifications', 'user_id');
        $hasStoreIdColumn = Schema::hasColumn('notifications', 'store_id');

        Schema::table('notifications', function (Blueprint $table) use ($hasTypeColumn, $hasAudienceScopeColumn, $hasUserIdColumn, $hasStoreIdColumn): void {
            if (!$hasAudienceScopeColumn) {
                if ($hasTypeColumn) {
                    $table->string('audience_scope', 32)->default('admin_shared')->after('type');
                } else {
                    $table->string('audience_scope', 32)->default('admin_shared')->after('message');
                }
                $table->index('audience_scope');
            }

            if (!$hasUserIdColumn) {
                $table->string('user_id', 100)->nullable()->after('audience_scope');
                $table->index('user_id');
            }

            if (!$hasStoreIdColumn) {
                $table->string('store_id', 100)->nullable()->after('user_id');
                $table->index('store_id');
            }
        });

        $hasAudienceScopeColumn = Schema::hasColumn('notifications', 'audience_scope');
        $hasUserIdColumn = Schema::hasColumn('notifications', 'user_id');
        $hasStoreIdColumn = Schema::hasColumn('notifications', 'store_id');

        if (!$hasAudienceScopeColumn) {
            return;
        }

        if ($hasUserIdColumn) {
            DB::table('notifications')
                ->whereNotNull('user_id')
                ->where(function ($query): void {
                    $query
                        ->whereNull('audience_scope')
                        ->orWhere('audience_scope', '')
                        ->orWhere('audience_scope', 'admin_shared');
                })
                ->update([
                    'audience_scope' => 'user_private',
                ]);
        }

        if ($hasStoreIdColumn) {
            $storePrivateQuery = DB::table('notifications')
                ->whereNotNull('store_id')
                ->where(function ($query): void {
                    $query
                        ->whereNull('audience_scope')
                        ->orWhere('audience_scope', '')
                        ->orWhere('audience_scope', 'admin_shared');
                });

            if ($hasUserIdColumn) {
                $storePrivateQuery->whereNull('user_id');
            }

            $storePrivateQuery->update([
                'audience_scope' => 'store_private',
            ]);
        }

        DB::table('notifications')
            ->where(function ($query): void {
                $query
                    ->whereNull('audience_scope')
                    ->orWhere('audience_scope', '');
            })
            ->update([
                'audience_scope' => 'admin_shared',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('notifications')) {
            return;
        }

        $dropColumns = [];

        if (Schema::hasColumn('notifications', 'store_id')) {
            $dropColumns[] = 'store_id';
        }

        if (Schema::hasColumn('notifications', 'user_id')) {
            $dropColumns[] = 'user_id';
        }

        if (Schema::hasColumn('notifications', 'audience_scope')) {
            $dropColumns[] = 'audience_scope';
        }

        if (!empty($dropColumns)) {
            Schema::table('notifications', function (Blueprint $table) use ($dropColumns): void {
                $table->dropColumn($dropColumns);
            });
        }
    }
};
