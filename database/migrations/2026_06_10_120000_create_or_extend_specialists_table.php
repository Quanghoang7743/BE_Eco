<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $created = false;

        if (! Schema::hasTable('specialists')) {
            Schema::create('specialists', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('specialist_code', 100)->nullable()->unique();
                $table->string('tax_code', 50)->nullable();
                $table->string('contract_file_url', 2048)->nullable();
                $table->string('id_card_front_url', 2048)->nullable();
                $table->string('id_card_back_url', 2048)->nullable();
                $table->longText('other_documents_json')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });

            $created = true;
        } else {
            $this->extendExistingTable();
        }

        if ($created) {
            $now = now();

            DB::table('users')
                ->where('role', 'sale')
                ->orderBy('id')
                ->select('id')
                ->chunkById(200, function ($users) use ($now): void {
                    $rows = $users->map(static fn ($user): array => [
                        'user_id' => (int) $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    if ($rows !== []) {
                        DB::table('specialists')->insertOrIgnore($rows);
                    }
                });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('specialists')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('specialists', 'contract_file_url') ? 'contract_file_url' : null,
            Schema::hasColumn('specialists', 'id_card_front_url') ? 'id_card_front_url' : null,
            Schema::hasColumn('specialists', 'id_card_back_url') ? 'id_card_back_url' : null,
            Schema::hasColumn('specialists', 'other_documents_json') ? 'other_documents_json' : null,
        ]));

        if ($columns !== []) {
            Schema::table('specialists', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function extendExistingTable(): void
    {
        $hasUserId = Schema::hasColumn('specialists', 'user_id');
        $hasContract = Schema::hasColumn('specialists', 'contract_file_url');
        $hasFront = Schema::hasColumn('specialists', 'id_card_front_url');
        $hasBack = Schema::hasColumn('specialists', 'id_card_back_url');
        $hasOther = Schema::hasColumn('specialists', 'other_documents_json');

        Schema::table('specialists', function (Blueprint $table) use (
            $hasUserId,
            $hasContract,
            $hasFront,
            $hasBack,
            $hasOther
        ): void {
            if (! $hasUserId) {
                $table->unsignedBigInteger('user_id')->nullable()->unique();
            }
            if (! $hasContract) {
                $table->string('contract_file_url', 2048)->nullable();
            }
            if (! $hasFront) {
                $table->string('id_card_front_url', 2048)->nullable();
            }
            if (! $hasBack) {
                $table->string('id_card_back_url', 2048)->nullable();
            }
            if (! $hasOther) {
                $table->longText('other_documents_json')->nullable();
            }
        });
    }
};
