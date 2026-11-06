<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MedicationRequest;
use App\Models\Elder;
use App\Models\Medication;
use App\Models\MedicationOccurrence;
use App\Models\Reminder;
use App\Services\CareAccess;
use App\Services\MedicationService;
use Illuminate\Http\Request;

class MedicationController extends Controller
{
    public function __construct(private CareAccess $access, private MedicationService $service) {}

    public function index(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder);

        return Medication::where('elder_id', $elder->id)->with(['schedules' => fn ($q) => $q->where('active', true)])->paginate(30);
    }

    public function store(MedicationRequest $r, Elder $elder)
    {
        return $this->save($r, $elder);
    }

    public function update(MedicationRequest $r, Elder $elder, int $medication)
    {
        return $this->save($r, $elder, $medication);
    }

    private function save(MedicationRequest $r, Elder $elder, ?int $id = null)
    {
        $d = $r->validated();

        return $this->access->write($r->user(), $elder, 'family', function (Elder $e) use ($r, $d, $id) {
            $values = collect($d)->except(['times', 'version'])->all();
            if ($id) {
                $m = Medication::where('elder_id', $e->id)->findOrFail($id);
                $this->access->version($m, $d['version']);
                $m->update([...$values, 'version' => $m->version + 1]);
            } else {
                $m = Medication::create([...$values, 'elder_id' => $e->id, 'created_by' => $r->user()->id]);
            }
            $this->service->replaceSchedules($e, $m, $d['times']);
            $this->access->audit($r->user(), $e, $id ? 'medication.updated' : 'medication.created', $m);

            return response()->json(['data' => $m->load(['schedules' => fn ($q) => $q->where('active', true)])], $id ? 200 : 201);
        });
    }

    public function occurrences(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder);
        $d = $r->validate(['date' => 'required|date_format:Y-m-d']);

        return MedicationOccurrence::where('elder_id', $elder->id)->where('local_date', $d['date'])->orderBy('scheduled_at')->paginate(100);
    }

    public function confirm(Request $r, Elder $elder, int $occurrence)
    {
        return $this->access->write($r->user(), $elder, 'confirm', function (Elder $e) use ($r, $occurrence) {
            $o = MedicationOccurrence::where('elder_id', $e->id)->findOrFail($occurrence);
            if ($o->status === 'confirmed') {
                return ['data' => $o];
            }
            abort_unless($o->status === 'pending' && $o->scheduled_at->lte(now()), 409, 'A ocorrência ainda não chegou ao horário ou foi cancelada.');
            $o->update(['status' => 'confirmed', 'confirmed_by' => $r->user()->id, 'confirmed_at' => now()]);
            Reminder::where('source_type', 'medication')->where('source_id', $o->id)->whereIn('status', ['pending', 'processed'])->update(['status' => 'cancelled']);
            $this->access->audit($r->user(), $e, 'medication.reported', $o);

            return ['data' => $o];
        });
    }
}
