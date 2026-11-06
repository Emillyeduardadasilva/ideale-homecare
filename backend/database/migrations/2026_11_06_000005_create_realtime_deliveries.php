<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('care_notification_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('lease_until')->nullable();
            $table->string('last_error', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_deliveries');
    }
};
