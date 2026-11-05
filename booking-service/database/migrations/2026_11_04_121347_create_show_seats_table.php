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
    Schema::create('show_seats', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('show_id');
        $table->unsignedBigInteger('seat_id');
        $table->string('seat_number', 20);
        $table->decimal('ticket_price', 10, 2);

        $table->string('status', 20)->default('AVAILABLE');
        $table->unsignedBigInteger('locked_by')->nullable();
        $table->uuid('lock_token')->nullable();
        $table->dateTime('lock_expires_at')->nullable();
        $table->unsignedBigInteger('booking_id')->nullable();

        $table->timestamps();

        $table->unique(['show_id', 'seat_id']);
        $table->index(['show_id', 'status']);
        $table->index('lock_expires_at');
    });
}

public function down(): void
{
    Schema::dropIfExists('show_seats');
}
};
