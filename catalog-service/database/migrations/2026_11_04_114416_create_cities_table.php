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
    Schema::create('cities', function (Blueprint $table) {
        $table->id();
        $table->string('name', 100);
        $table->string('state', 100)->nullable();
        $table->string('country', 100)->default('India');
        $table->boolean('is_active')->default(true);
        $table->timestamps();

        $table->unique(['name', 'state']);
    });
}

public function down(): void
{
    Schema::dropIfExists('cities');
}
};
