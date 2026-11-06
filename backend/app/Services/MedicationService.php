<?php

namespace App\Services;

use App\Models\Elder;
use App\Models\Medication;
use App\Models\MedicationOccurrence;
use App\Models\MedicationSchedule;
use App\Models\Reminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MedicationService
{
    // Caller holds the elder row lock; the scheduler takes the same lock.
    public function replaceSchedules(Elder $elder, Medication $medication, array $times): void
    {
        MedicationSchedule::where('medication_id', $medication->id)->update(['active' => false]);
        $ids = MedicationOccurrence::where('medication_id', $medication->id)->where('status', 'pending')->where('scheduled_at', '>=', now())->pluck('id');
        MedicationOccurrence::whereIn('id', $ids)->update(['status' => 'cancelled']);
        Reminder::where('source_type', 'medication')->whereIn('source_id', $ids)->whereIn('status', ['pending', 'processed'])->update(['status' => 'cancelled']);
        if (! $medication->active) {
            return;
        }
        foreach ($times as $time) {
            MedicationSchedule::create(['medication_id' => $medication->id, 'local_time' => $time, 'timezone' => $elder->timezone, 'dosage' => $medication->dosage, 'effective_at' => now()]);
        }
        $this->materialize($medication);
    }

    public function materialize(Medication $medication): void
    {
        if (! $medication->active) {
            return;
        }
        foreach ($medication->schedules()->where('active', true)->get() as $schedule) {
            $start = CarbonImmutable::parse($medication->starts_on->format('Y-m-d'), $schedule->timezone)->max($schedule->effective_at->setTimezone($schedule->timezone)->startOfDay());
            $end = CarbonImmutable::now($schedule->timezone)->addDays(config('care.medication_horizon_days'))->startOfDay();
            if ($medication->ends_on) {
                $end = $end->min(CarbonImmutable::parse($medication->ends_on->format('Y-m-d'), $schedule->timezone)->endOfDay());
            }
            // A bounded rolling window; historical occurrences remain intact.
            $start = $start->max(CarbonImmutable::now($schedule->timezone)->subDays(7)->startOfDay());
            for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                $at = CarbonImmutable::parse($day->format('Y-m-d').' '.$schedule->local_time, $schedule->timezone);
                if ($at->format('H:i') !== $schedule->local_time || $at->utc()->lt($schedule->effective_at)) {
                    continue;
                }
                $o = MedicationOccurrence::firstOrCreate(['medication_schedule_id' => $schedule->id, 'scheduled_at' => $at->utc()], ['elder_id' => $medication->elder_id, 'medication_id' => $medication->id, 'local_date' => $day->format('Y-m-d'), 'dosage' => $schedule->dosage]);
                if ($o->status === 'pending') {
                    Reminder::firstOrCreate(['source_type' => 'medication', 'source_id' => $o->id, 'source_version' => $medication->version], ['elder_id' => $medication->elder_id, 'due_at' => $at->utc()]);
                }
            }
        }
    }

    public function generate(): void
    {
        Medication::where('active', true)->select(['id', 'elder_id'])->chunkById(100, function ($medications) {
            foreach ($medications as $medication) {
                DB::transaction(function () use ($medication) {
                    $e = Elder::whereKey($medication->elder_id)->lockForUpdate()->first();
                    if ($e?->active) {
                        $this->materialize($medication->fresh());
                    }
                }, 3);
            }
        });
    }
}
