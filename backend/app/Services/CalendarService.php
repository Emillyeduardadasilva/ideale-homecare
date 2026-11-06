<?php

namespace App\Services;

use App\Models\Elder;
use App\Models\FamilyEvent;
use App\Models\MedicationOccurrence;
use App\Models\Task;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;

class CalendarService
{
    public function __construct(private CareAccess $access) {}

    public function forDay(User $user, Elder $elder, string $date): array
    {
        $this->access->check($user, $elder);
        $local = CarbonImmutable::parse($date, $elder->timezone)->startOfDay();
        $start = $local->utc();
        $end = $local->addDay()->utc();
        $family = $this->access->allows($user, $elder, 'family');
        $confirm = $this->access->allows($user, $elder, 'confirm');
        $schedule = $this->access->allows($user, $elder, 'schedule');
        $professional = $this->access->allows($user, $elder, 'professional');
        $items = collect();
        foreach (Task::with('assignees')->where('elder_id', $elder->id)->where('due_at', '>=', $start)->where('due_at', '<', $end)->where('status', '!=', 'cancelled')->get() as $task) {
            $actions = $family && $task->status === 'pending' ? ['edit', 'cancel'] : [];
            if ($confirm && $task->status === 'pending' && ($elder->user_id === $user->id || $task->assignees->contains('id', $user->id))) {
                $actions[] = 'complete';
            }
            $items->push(['id' => 'task:'.$task->id, 'type' => 'task', 'source_id' => $task->id, 'title' => $task->title, 'starts_at' => $task->due_at, 'status' => $task->status, 'version' => $task->version, 'allowed_actions' => $actions]);
        }
        foreach (MedicationOccurrence::with('medication:id,name')->where('elder_id', $elder->id)->where('scheduled_at', '>=', $start)->where('scheduled_at', '<', $end)->where('status', '!=', 'cancelled')->get() as $occurrence) {
            $items->push(['id' => 'medication:'.$occurrence->id, 'type' => 'medication', 'source_id' => $occurrence->id, 'medication_id' => $occurrence->medication_id, 'medication_name' => $occurrence->medication?->name, 'title' => 'Medicação programada', 'starts_at' => $occurrence->scheduled_at, 'status' => $occurrence->status, 'dosage' => $occurrence->dosage,
                'allowed_actions' => $confirm && $occurrence->status === 'pending' && $occurrence->scheduled_at->lte(now()) ? ['confirm'] : []]);
        }
        foreach (Visit::with('professional:id,user_id')->where('elder_id', $elder->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->where('status', '!=', 'cancelled')->get() as $visit) {
            $actions = $schedule && in_array($visit->status, ['scheduled', 'confirmed']) ? ['reschedule', 'cancel'] : [];
            if ($schedule || ($professional && $visit->professional->user_id === $user->id)) {
                if ($visit->status === 'scheduled') {
                    $actions[] = 'confirm';
                } elseif ($visit->status === 'confirmed' && $visit->starts_at->lte(now())) {
                    $actions[] = 'complete';
                }
            }
            $items->push(['id' => 'visit:'.$visit->id, 'type' => 'visit', 'source_id' => $visit->id, 'title' => 'Visita: '.$visit->specialty, 'starts_at' => $visit->starts_at, 'ends_at' => $visit->ends_at, 'status' => $visit->status, 'version' => $visit->version, 'allowed_actions' => $actions]);
        }
        foreach (FamilyEvent::where('elder_id', $elder->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->where('active', true)->get() as $event) {
            $items->push(['id' => 'event:'.$event->id, 'type' => 'event', 'source_id' => $event->id, 'title' => $event->title, 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at, 'version' => $event->version, 'allowed_actions' => $family ? ['edit'] : []]);
        }

        return ['data' => $items->sortBy('starts_at')->values(), 'date' => $date, 'timezone' => $elder->timezone];
    }
}
