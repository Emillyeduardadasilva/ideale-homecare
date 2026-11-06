<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FamilyEventRequest;
use App\Models\Elder;
use App\Models\FamilyEvent;
use App\Services\CalendarService;
use App\Services\CareAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function __construct(private CareAccess $access) {}

    public function index(Request $r, Elder $elder, CalendarService $calendar)
    {
        $data = $r->validate(['date' => 'required|date_format:Y-m-d']);

        return $calendar->forDay($r->user(), $elder, $data['date']);
    }

    public function store(FamilyEventRequest $r, Elder $elder)
    {
        return $this->save($r, $elder);
    }

    public function update(FamilyEventRequest $r, Elder $elder, int $event)
    {
        return $this->save($r, $elder, $event);
    }

    private function save(FamilyEventRequest $r, Elder $elder, ?int $id = null)
    {
        $d = $r->validated();

        return $this->access->write($r->user(), $elder, 'family', function (Elder $e) use ($r, $d, $id) {
            $d['starts_at'] = CarbonImmutable::parse($d['starts_at'])->utc();
            $d['ends_at'] = CarbonImmutable::parse($d['ends_at'])->utc();
            if ($id) {
                $event = FamilyEvent::where('elder_id', $e->id)->findOrFail($id);
                $this->access->version($event, $d['version']);
                $event->update([...$d, 'version' => $event->version + 1]);
            } else {
                $event = FamilyEvent::create([...$d, 'elder_id' => $e->id, 'created_by' => $r->user()->id]);
            }
            $this->access->audit($r->user(), $e, $id ? 'event.updated' : 'event.created', $event);

            return response()->json(['data' => $event], $id ? 200 : 201);
        });
    }
}
