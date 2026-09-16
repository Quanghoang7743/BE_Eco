<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE draw_blockchain DROP FOREIGN KEY draw_blockchain_draw_id_foreign');
        DB::statement('ALTER TABLE draw_logs DROP FOREIGN KEY draw_logs_draw_id_foreign');
        DB::statement('ALTER TABLE draws MODIFY COLUMN id CHAR(36) NOT NULL');
        DB::statement('ALTER TABLE draw_blockchain MODIFY COLUMN draw_id CHAR(36) NULL');
        DB::statement('ALTER TABLE draw_logs MODIFY COLUMN draw_id CHAR(36) NULL');
        DB::statement('ALTER TABLE draw_blockchain ADD CONSTRAINT draw_blockchain_draw_id_foreign FOREIGN KEY (draw_id) REFERENCES draws(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE draw_logs ADD CONSTRAINT draw_logs_draw_id_foreign FOREIGN KEY (draw_id) REFERENCES draws(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE draw_blockchain DROP FOREIGN KEY draw_blockchain_draw_id_foreign');
        DB::statement('ALTER TABLE draw_logs DROP FOREIGN KEY draw_logs_draw_id_foreign');
        DB::statement('ALTER TABLE draw_blockchain MODIFY COLUMN draw_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE draw_logs MODIFY COLUMN draw_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE draws MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        DB::statement('ALTER TABLE draw_blockchain ADD CONSTRAINT draw_blockchain_draw_id_foreign FOREIGN KEY (draw_id) REFERENCES draws(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE draw_logs ADD CONSTRAINT draw_logs_draw_id_foreign FOREIGN KEY (draw_id) REFERENCES draws(id) ON DELETE CASCADE');
    }
};
