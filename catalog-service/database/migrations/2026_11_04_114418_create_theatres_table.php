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
    Schema::create('theatres', function (Blueprint $table) {
        $table->id();
        $table->foreignId('city_id')->constrained()->restrictOnDelete();
        $table->string('name');
        $table->string('address')->nullable();
        $table->string('pincode', 10)->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('theatres');
}
};
