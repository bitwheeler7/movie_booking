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

        $table->unsignedBigInteger('booking_id');
        $table->unsignedBigInteger('user_id');

        $table->string('payment_reference', 50)->unique();
        $table->string('idempotency_key', 100)->unique();

        $table->decimal('amount', 10, 2);

        $table->string('currency', 3)->default('INR');

        $table->string('payment_method', 30)->nullable();

        $table->string('gateway_name', 50)->nullable();
        $table->string('gateway_transaction_id', 100)->nullable();

        $table->string('status', 20)->default('PENDING');

        $table->dateTime('paid_at')->nullable();

        $table->json('gateway_response')->nullable();

        $table->timestamps();

        $table->index(['booking_id', 'status']);
        $table->index('gateway_transaction_id');
    });
}

public function down(): void
{
    Schema::dropIfExists('payments');
}
};
