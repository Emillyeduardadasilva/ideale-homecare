<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_shifts', function (Blueprint $table) {
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->index(['elder_id', 'active', 'starts_at'], 'care_shift_active_start');
        });
        Schema::table('tasks', fn (Blueprint $table) => $table->index(['elder_id', 'due_at']));
        Schema::table('medication_occurrences', fn (Blueprint $table) => $table->index(['elder_id', 'local_date', 'scheduled_at'], 'occurrence_elder_date_time'));
        Schema::table('observations', fn (Blueprint $table) => $table->index(['elder_id', 'occurred_at', 'id'], 'observation_elder_time'));
    }

    public function down(): void
    {
        // InnoDB may replace the implicit foreign-key index with a covering index.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            foreach (['medication_occurrences', 'tasks', 'care_shifts'] as $tableName) {
                if (! Schema::hasIndex($tableName, ['elder_id'])) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->index('elder_id'));
                }
            }
        }
        Schema::table('observations', fn (Blueprint $table) => $table->dropIndex('observation_elder_time'));
        Schema::table('medication_occurrences', fn (Blueprint $table) => $table->dropIndex('occurrence_elder_date_time'));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex(['elder_id', 'due_at']));
        Schema::table('care_shifts', function (Blueprint $table) {
            $table->dropIndex('care_shift_active_start');
            $table->dropColumn(['active', 'version']);
        });
    }
};
