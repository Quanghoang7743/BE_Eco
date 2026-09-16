<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('specialist_rating_periods')) {
            Schema::create('specialist_rating_periods', function (Blueprint $table): void {
                $table->id();
                $table->string('period_type', 20)->default('month')->index();
                $table->date('period_from')->index();
                $table->date('period_to')->index();
                $table->string('name', 100)->nullable();
                $table->string('status', 30)->default('open')->index();
                $table->timestamps();

                $table->unique(['period_type', 'period_from', 'period_to'], 'uniq_specialist_rating_period');
            });
        }

        if (Schema::hasTable('specialist_ratings')) {
            Schema::table('specialist_ratings', function (Blueprint $table): void {
                if (! Schema::hasColumn('specialist_ratings', 'rating_period_id')) {
                    $table->unsignedBigInteger('rating_period_id')->nullable()->index()->after('specialist_id');
                }

                if (! Schema::hasColumn('specialist_ratings', 'total_score')) {
                    $table->decimal('total_score', 6, 2)->default(0)->after('rating_period_id');
                }

                if (! Schema::hasColumn('specialist_ratings', 'star_level')) {
                    $table->unsignedTinyInteger('star_level')->default(1)->after('total_score');
                }

                if (! Schema::hasColumn('specialist_ratings', 'previous_star_level')) {
                    $table->unsignedTinyInteger('previous_star_level')->nullable()->after('star_level');
                }

                if (! Schema::hasColumn('specialist_ratings', 'status')) {
                    $table->string('status', 30)->default('DRAFT')->index()->after('previous_star_level');
                }

                if (! Schema::hasColumn('specialist_ratings', 'reviewed_by')) {
                    $table->unsignedBigInteger('reviewed_by')->nullable()->index()->after('status');
                }

                if (! Schema::hasColumn('specialist_ratings', 'reviewed_at')) {
                    $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
                }

                if (! Schema::hasColumn('specialist_ratings', 'calculated_at')) {
                    $table->timestamp('calculated_at')->nullable()->index()->after('reviewed_at');
                }

                if (! Schema::hasColumn('specialist_ratings', 'notified_at')) {
                    $table->timestamp('notified_at')->nullable()->after('approved_at');
                }

                if (! Schema::hasColumn('specialist_ratings', 'data_source_json')) {
                    $table->json('data_source_json')->nullable()->after('notes');
                }

                if (! Schema::hasColumn('specialist_ratings', 'adjustment_reason')) {
                    $table->text('adjustment_reason')->nullable()->after('data_source_json');
                }
            });

            if (Schema::hasColumn('specialist_ratings', 'score')) {
                DB::table('specialist_ratings')
                    ->where('total_score', 0)
                    ->update(['total_score' => DB::raw('score')]);
            }

            if (Schema::hasColumn('specialist_ratings', 'stars')) {
                DB::table('specialist_ratings')
                    ->where('star_level', 1)
                    ->where('stars', '>', 0)
                    ->update(['star_level' => DB::raw('stars')]);
            }
        }

        if (! Schema::hasTable('specialist_rating_criteria_scores')) {
            Schema::create('specialist_rating_criteria_scores', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('rating_id')->index();
                $table->string('criteria_code', 50)->index();
                $table->decimal('raw_score', 6, 2)->default(0);
                $table->decimal('weight_percent', 5, 2)->default(20);
                $table->decimal('weighted_score', 6, 2)->default(0);
                $table->json('data_source_json')->nullable();
                $table->timestamps();

                $table->unique(['rating_id', 'criteria_code'], 'uniq_specialist_rating_criteria');
            });
        }

        if (! Schema::hasTable('specialist_rating_review_requests')) {
            Schema::create('specialist_rating_review_requests', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('rating_id')->index();
                $table->unsignedBigInteger('specialist_id')->index();
                $table->text('request_reason');
                $table->string('status', 30)->default('SUBMITTED')->index();
                $table->decimal('old_score', 6, 2)->nullable();
                $table->decimal('new_score', 6, 2)->nullable();
                $table->text('admin_response')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable()->index();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->index(['specialist_id', 'status'], 'idx_specialist_rating_review_status');
            });
        }

        if (! Schema::hasTable('specialist_rating_audit_logs')) {
            Schema::create('specialist_rating_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('rating_id')->index();
                $table->unsignedBigInteger('specialist_id')->index();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('action', 80)->index();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('specialist_rating_audit_logs');
        Schema::dropIfExists('specialist_rating_review_requests');
        Schema::dropIfExists('specialist_rating_criteria_scores');
        Schema::dropIfExists('specialist_rating_periods');
    }
};
