<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            $table->uuid('store_id')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->date('reconciliation_date')->nullable();
            $table->decimal('gross_revenue', 15, 2)->default(0); // doanh thu gộp
            $table->decimal('net_revenue', 15, 2)->default(0); // thực nhận
            $table->text('notes')->nullable();
            $table->timestamp('date_confirmed')->nullable(); // ngày xác nhận từ store admin
            $table->unsignedBigInteger('approved_by')->nullable(); // admin who approved
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
