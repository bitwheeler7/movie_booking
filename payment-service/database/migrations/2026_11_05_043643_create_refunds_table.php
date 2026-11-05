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
    Schema::create('refunds', function (Blueprint $table) {
        $table->id();

        $table->foreignId('payment_id')
            ->constrained()
            ->restrictOnDelete();

        $table->string('refund_reference', 50)->unique();

        $table->string('gateway_refund_id', 100)->nullable();

        $table->decimal('amount', 10, 2);

        $table->string('reason', 255)->nullable();

        $table->string('status', 20)->default('PENDING');

        $table->dateTime('refunded_at')->nullable();

        $table->json('gateway_response')->nullable();

        $table->timestamps();

        $table->index(['payment_id', 'status']);
    });
}

public function down(): void
{
    Schema::dropIfExists('refunds');
}
};
