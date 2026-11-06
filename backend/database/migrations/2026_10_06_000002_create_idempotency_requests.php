<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->char('key_hash', 64);
            $t->char('request_hash', 64);
            $t->unsignedSmallInteger('status')->nullable();
            $t->longText('response')->nullable();
            $t->timestamp('expires_at')->index();
            $t->unique(['user_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_requests');
    }
};
