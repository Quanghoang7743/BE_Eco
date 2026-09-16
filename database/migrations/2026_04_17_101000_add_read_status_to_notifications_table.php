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
        if (!Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'title')) {
                $table->string('title', 191)->nullable()->after('message');
            }

            if (!Schema::hasColumn('notifications', 'body')) {
                $table->text('body')->nullable()->after('title');
            }

            if (!Schema::hasColumn('notifications', 'type')) {
                $table->string('type', 80)->default('system')->after('order_id');
                $table->index('type');
            }

            if (!Schema::hasColumn('notifications', 'action_url')) {
                $table->string('action_url', 500)->nullable()->after('type');
            }

            if (!Schema::hasColumn('notifications', 'is_read')) {
                $table->boolean('is_read')->default(false)->after('action_url');
                $table->index('is_read');
            }

            if (!Schema::hasColumn('notifications', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('is_read');
            }
        });

        if (Schema::hasColumn('notifications', 'body')) {
            DB::table('notifications')
                ->whereNull('body')
                ->update([
                    'body' => DB::raw('message'),
                ]);
        }
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

        if (Schema::hasColumn('notifications', 'read_at')) {
            $dropColumns[] = 'read_at';
        }

        if (Schema::hasColumn('notifications', 'is_read')) {
            $dropColumns[] = 'is_read';
        }

        if (Schema::hasColumn('notifications', 'action_url')) {
            $dropColumns[] = 'action_url';
        }

        if (Schema::hasColumn('notifications', 'type')) {
            $dropColumns[] = 'type';
        }

        if (Schema::hasColumn('notifications', 'body')) {
            $dropColumns[] = 'body';
        }

        if (Schema::hasColumn('notifications', 'title')) {
            $dropColumns[] = 'title';
        }

        if (!empty($dropColumns)) {
            Schema::table('notifications', function (Blueprint $table) use ($dropColumns) {
                $table->dropColumn($dropColumns);
            });
        }
    }
};
