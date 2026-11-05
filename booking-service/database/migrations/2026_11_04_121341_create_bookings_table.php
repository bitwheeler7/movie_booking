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
    Schema::create('bookings', function (Blueprint $table) {
        $table->id();
        $table->string('booking_reference', 40)->unique();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('show_id');
        $table->decimal('total_amount', 10, 2)->default(0);
        $table->string('booking_status', 20)->default('PENDING');
        $table->string('payment_status', 20)->default('PENDING');
        $table->uuid('lock_token');
        $table->dateTime('lock_expires_at');
        $table->dateTime('confirmed_at')->nullable();
        $table->timestamps();

        $table->index(['user_id', 'booking_status']);
        $table->index(['booking_status', 'lock_expires_at']);
        $table->index('show_id');
    });
}

public function down(): void
{
    Schema::dropIfExists('bookings');
}
};
