<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('description', 160);
            $table->decimal('amount', 12, 2);
            $table->string('type', 12);
            $table->string('category', 80)->default('Outros');
            $table->date('due_date')->nullable();
            $table->string('status', 12)->default('pending');
            $table->timestamps();
            $table->index(['user_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_entries');
    }
};
