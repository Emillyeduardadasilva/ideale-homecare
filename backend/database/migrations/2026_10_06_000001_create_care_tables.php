<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        $create = function (string $table, Closure $callback): void {
            if (!Schema::hasTable($table)) {
                Schema::create($table, $callback);
            }
        };

        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'role')) {
                $t->string('role', 24)->default('family');
            }

            if (!Schema::hasColumn('users', 'active')) {
                $t->boolean('active')->default(true);
            }
        });

        $create('elders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('name');
            $t->date('birth_date');
            $t->text('medical_information')->nullable();
            $t->string('timezone', 64)->default('America/Cuiaba');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        $create('care_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('role', 24);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['elder_id', 'user_id']);
        });

        $create('care_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('email');
            $t->string('role', 24);
            $t->char('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });

        $create('professionals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('specialty', 100);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        $create('tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->timestamp('due_at')->index();
            $t->string('status', 24)->default('pending');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        $create('task_user', function (Blueprint $t) {
            $t->foreignId('task_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->primary(['task_id', 'user_id']);
        });

        $create('task_completions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamp('completed_at');
            $t->timestamps();
        });

        $create('medications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('name', 200);
            $t->string('dosage', 200);
            $t->text('instructions')->nullable();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        $create('medication_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('medication_id')->constrained()->restrictOnDelete();
            $t->string('local_time', 5);
            $t->string('timezone', 64);
            $t->string('dosage', 200);
            $t->timestamp('effective_at');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        $create('medication_occurrences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->foreignId('medication_id')->constrained()->restrictOnDelete();
            $t->foreignId('medication_schedule_id')->constrained()->restrictOnDelete();
            $t->timestamp('scheduled_at')->index();
            $t->date('local_date');
            $t->string('dosage', 200);
            $t->string('status', 24)->default('pending');
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
            $t->unique(['medication_schedule_id', 'scheduled_at'], 'occurrence_schedule_time_unique');
        });

        $create('observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->text('text');
            $t->json('tags')->nullable();
            $t->timestamp('occurred_at');
            $t->foreignId('corrects_id')->nullable()->constrained('observations')->restrictOnDelete();
            $t->timestamps();
            $t->index(['elder_id', 'created_at']);
        });

        $create('measurements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('type', 24);
            $t->decimal('value', 8, 2);
            $t->decimal('secondary_value', 8, 2)->nullable();
            $t->string('unit', 16);
            $t->timestamp('measured_at');
            $t->timestamps();
            $t->index(['elder_id', 'measured_at']);
        });

        $create('visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->foreignId('professional_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('specialty', 100);
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('status', 24)->default('scheduled');
            $t->timestamp('completed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['professional_id', 'status', 'starts_at']);
            $t->index(['elder_id', 'starts_at']);
        });

        $create('observation_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('observation_id')->constrained()->restrictOnDelete();
            $t->foreignId('visit_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamp('read_at');
            $t->unique(['observation_id', 'visit_id', 'user_id'], 'observation_reader_unique');
        });

        $create('family_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        $create('emergency_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->string('name', 200);
            $t->string('phone', 24);
            $t->string('type', 24);
            $t->unsignedSmallInteger('priority')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        $create('care_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->foreignId('emergency_contact_id')->constrained()->restrictOnDelete();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->timestamps();
        });

        $create('reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->string('source_type', 24);
            $t->unsignedBigInteger('source_id');
            $t->unsignedInteger('source_version');
            $t->timestamp('due_at');
            $t->string('status', 24)->default('pending');
            $t->timestamps();
            $t->unique(['source_type', 'source_id', 'source_version']);
            $t->index(['status', 'due_at']);
        });

        $create('care_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('elder_id')->constrained()->restrictOnDelete();
            $t->foreignId('reminder_id')->constrained()->restrictOnDelete();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'reminder_id']);
        });

        $create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->char('endpoint_hash', 64)->unique();
            $t->text('subscription');
            $t->timestamps();
        });

        $create('push_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('care_notification_id')->constrained()->restrictOnDelete();
            $t->foreignId('push_subscription_id')->constrained()->cascadeOnDelete();
            $t->string('status', 24)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('available_at');
            $t->timestamp('lease_until')->nullable();
            $t->string('last_error', 64)->nullable();
            $t->timestamps();
            $t->unique(['care_notification_id', 'push_subscription_id'], 'push_delivery_unique');
            $t->index(['status', 'available_at']);
        });

        $create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('elder_id')->nullable()->index();
            $t->string('action', 100);
            $t->string('entity', 100);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->uuid('request_id')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_logs',
            'push_deliveries',
            'push_subscriptions',
            'care_notifications',
            'reminders',
            'care_shifts',
            'emergency_contacts',
            'family_events',
            'observation_reads',
            'visits',
            'measurements',
            'observations',
            'medication_occurrences',
            'medication_schedules',
            'medications',
            'task_completions',
            'task_user',
            'tasks',
            'professionals',
            'care_invitations',
            'care_memberships',
            'elders'
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'role')) {
                $t->dropColumn('role');
            }

            if (Schema::hasColumn('users', 'active')) {
                $t->dropColumn('active');
            }
        });
    }
};
