<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable()->unique();
            $table->string('payment_reference', 50)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['payment_id']);
            $table->dropUnique(['payment_reference']);
            $table->dropColumn(['payment_id', 'payment_reference']);
        });
    }
};
