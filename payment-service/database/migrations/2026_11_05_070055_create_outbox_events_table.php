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
    Schema::create('outbox_events', function (Blueprint $table) {
        $table->id();
        $table->uuid('event_id')->unique();
        $table->string('topic', 150);
        $table->json('payload');
        $table->string('status', 20)->default('PENDING');
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamp('available_at')->nullable();
        $table->timestamp('locked_at')->nullable();
        $table->timestamp('published_at')->nullable();
        $table->text('last_error')->nullable();
        $table->timestamps();

        $table->index(['status', 'available_at']);
    });
}

public function down(): void
{
    Schema::dropIfExists('outbox_events');
}
};
