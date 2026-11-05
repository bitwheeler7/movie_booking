<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 32)->nullable()->unique();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('show_id');
            $table->json('seats');
            $table->string('status', 20)->default('ISSUED');
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
