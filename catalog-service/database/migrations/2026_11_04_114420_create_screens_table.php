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
    Schema::create('screens', function (Blueprint $table) {
        $table->id();
        $table->foreignId('theatre_id')->constrained()->cascadeOnDelete();
        $table->string('name', 100);
        $table->unsignedInteger('total_seats')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();

        $table->unique(['theatre_id', 'name']);
    });
}

public function down(): void
{
    Schema::dropIfExists('screens');
}
};
