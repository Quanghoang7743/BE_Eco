<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('specialist_store_care_logs')) {
            Schema::table('specialist_store_care_logs', function (Blueprint $table): void {
                if (! Schema::hasColumn('specialist_store_care_logs', 'care_content')) {
                    $table->text('care_content')->nullable();
                }

                if (! Schema::hasColumn('specialist_store_care_logs', 'problem_detected')) {
                    $table->text('problem_detected')->nullable();
                }

                if (! Schema::hasColumn('specialist_store_care_logs', 'proposed_solution')) {
                    $table->text('proposed_solution')->nullable();
                }

                if (! Schema::hasColumn('specialist_store_care_logs', 'result_note')) {
                    $table->text('result_note')->nullable();
                }

                if (! Schema::hasColumn('specialist_store_care_logs', 'attachment_file_id')) {
                    $table->string('attachment_file_id', 500)->nullable();
                }

                if (! Schema::hasColumn('specialist_store_care_logs', 'attachment_original_name')) {
                    $table->string('attachment_original_name')->nullable();
                }

                if (! Schema::hasColumn('specialist_store_care_logs', 'attachment_mime_type')) {
                    $table->string('attachment_mime_type', 120)->nullable();
                }

                if (! Schema::hasColumn('specialist_store_care_logs', 'attachment_size')) {
                    $table->unsignedBigInteger('attachment_size')->nullable();
                }
            });

            if (
                Schema::hasColumn('specialist_store_care_logs', 'content')
                && Schema::hasColumn('specialist_store_care_logs', 'care_content')
            ) {
                DB::table('specialist_store_care_logs')
                    ->whereNull('care_content')
                    ->update(['care_content' => DB::raw('content')]);
            }
        }

        if (Schema::hasTable('specialist_tasks')) {
            Schema::table('specialist_tasks', function (Blueprint $table): void {
                if (! Schema::hasColumn('specialist_tasks', 'task_type')) {
                    $table->string('task_type', 80)->default('other')->index();
                }

                if (! Schema::hasColumn('specialist_tasks', 'deadline')) {
                    $table->timestamp('deadline')->nullable()->index();
                }

                if (! Schema::hasColumn('specialist_tasks', 'result_note')) {
                    $table->text('result_note')->nullable();
                }

                if (! Schema::hasColumn('specialist_tasks', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable()->index();
                }
            });

            if (
                Schema::hasColumn('specialist_tasks', 'due_at')
                && Schema::hasColumn('specialist_tasks', 'deadline')
            ) {
                DB::table('specialist_tasks')
                    ->whereNull('deadline')
                    ->update(['deadline' => DB::raw('due_at')]);
            }

            if (
                Schema::hasColumn('specialist_tasks', 'assigned_by')
                && Schema::hasColumn('specialist_tasks', 'created_by')
            ) {
                DB::table('specialist_tasks')
                    ->whereNull('created_by')
                    ->update(['created_by' => DB::raw('assigned_by')]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('specialist_store_care_logs')) {
            Schema::table('specialist_store_care_logs', function (Blueprint $table): void {
                foreach ([
                    'care_content',
                    'problem_detected',
                    'proposed_solution',
                    'result_note',
                    'attachment_file_id',
                    'attachment_original_name',
                    'attachment_mime_type',
                    'attachment_size',
                ] as $column) {
                    if (Schema::hasColumn('specialist_store_care_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('specialist_tasks')) {
            Schema::table('specialist_tasks', function (Blueprint $table): void {
                foreach (['task_type', 'deadline', 'result_note', 'created_by'] as $column) {
                    if (Schema::hasColumn('specialist_tasks', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
