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
    Schema::create('shows', function (Blueprint $table) {
        $table->id();
        $table->foreignId('movie_id')->constrained()->restrictOnDelete();
        $table->foreignId('screen_id')->constrained()->restrictOnDelete();
        $table->dateTime('start_time');
        $table->dateTime('end_time');
        $table->decimal('ticket_price', 10, 2);
        $table->string('status', 20)->default('SCHEDULED');
        $table->timestamps();

        $table->index(['screen_id', 'start_time']);
        $table->index(['movie_id', 'start_time']);
    });
}

public function down(): void
{
    Schema::dropIfExists('shows');
}
};
