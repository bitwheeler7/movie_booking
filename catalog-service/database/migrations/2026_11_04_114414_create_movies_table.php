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
    Schema::create('movies', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->text('description')->nullable();
        $table->string('language', 50)->nullable();
        $table->string('genre', 100)->nullable();
        $table->unsignedInteger('duration_minutes')->nullable();
        $table->date('release_date')->nullable();
        $table->string('certificate', 20)->nullable();
        $table->string('poster_url')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('movies');
}

};
