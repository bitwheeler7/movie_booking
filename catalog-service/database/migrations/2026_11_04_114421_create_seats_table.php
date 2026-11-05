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
    Schema::create('seats', function (Blueprint $table) {
        $table->id();
        $table->foreignId('screen_id')->constrained()->cascadeOnDelete();
        $table->string('seat_number', 20);
        $table->string('seat_type', 30)->default('REGULAR');
        $table->string('row_label', 10)->nullable();
        $table->decimal('base_price', 10, 2)->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();

        $table->unique(['screen_id', 'seat_number']);
    });
}

public function down(): void
{
    Schema::dropIfExists('seats');
}
};
