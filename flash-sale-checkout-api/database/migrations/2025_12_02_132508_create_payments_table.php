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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // unique idempotency key (prevents double processing)
            $table->string('idempotency_key')->unique();
            // optionally attached to an order (may be null if webhook arrives early)
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            // attachable to a hold (to allow out-of-order handling)
            $table->foreignId('hold_id')->nullable()->constrained('holds')->nullOnDelete();
            $table->string('status'); // success | failure
            $table->decimal('amount', 12, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('applied')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
