<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\VisitRequest;
use App\Models\Elder;
use App\Models\Observation;
use App\Models\Professional;
use App\Models\Visit;
use App\Services\CareAccess;
use App\Services\VisitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VisitController extends Controller
{
    public function __construct(private CareAccess $access, private VisitService $service) {}

    public function index(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder);

        return Visit::where('elder_id', $elder->id)->with('professional.user:id,name')->orderByDesc('starts_at')->paginate(30);
    }

    public function professionals(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder, 'schedule');

        return ['data' => Professional::where('active', true)->whereIn('user_id', DB::table('care_memberships')->select('user_id')->where('elder_id', $elder->id)->where('role', 'team')->where('active', true))->with('user:id,name')->get()];
    }

    public function store(VisitRequest $r, Elder $elder)
    {
        return response()->json(['data' => $this->service->save($r->user(), $elder, $r->validated())], 201);
    }

    public function update(VisitRequest $r, Elder $elder, int $visit)
    {
        return ['data' => $this->service->save($r->user(), $elder, $r->validated(), $visit)];
    }

    public function status(Request $r, Elder $elder, int $visit)
    {
        $d = $r->validate(['status' => 'required|in:confirmed,completed,cancelled', 'version' => 'required|integer|min:1']);

        return ['data' => $this->service->transition($r->user(), $elder, $visit, $d['status'], $d['version'])];
    }

    private function assigned(Request $r, Elder $elder, int $id): Visit
    {
        $this->access->check($r->user(), $elder, 'professional');
        $v = Visit::where('elder_id', $elder->id)->with('professional')->findOrFail($id);
        abort_unless($v->professional->user_id === $r->user()->id && $v->status !== 'cancelled', 403);

        return $v;
    }

    public function briefing(Request $r, Elder $elder, int $visit)
    {
        $v = $this->assigned($r, $elder, $visit);
        $since = Visit::where('elder_id', $elder->id)->where('status', 'completed')->where('completed_at', '<', $v->starts_at)->whereKeyNot($v->id)->max('completed_at');
        $notes = Observation::where('elder_id', $elder->id)->when($since, fn ($q) => $q->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('occurred_at', '>=', $since)))
            ->select('observations.*')->selectSub(DB::table('observation_reads')->select('read_at')->whereColumn('observation_id', 'observations.id')->where('visit_id', $v->id)->where('user_id', $r->user()->id), 'read_at')
            ->orderBy('created_at')->paginate(30);

        return response()->json([...$notes->toArray(), 'criteria' => ['since' => $since, 'reference' => 'last_completed_visit', 'includes_late_entries' => true]]);
    }

    public function read(Request $r, Elder $elder, int $visit)
    {
        $d = $r->validate(['observation_ids' => 'required|array|min:1|max:100', 'observation_ids.*' => 'required|integer|distinct']);

        return $this->access->write($r->user(), $elder, 'professional', function (Elder $e) use ($r, $visit, $d) {
            $v = $this->assigned($r, $e, $visit);
            $ids = Observation::where('elder_id', $e->id)->whereIn('id', $d['observation_ids'])->pluck('id');
            abort_unless($ids->count() === count($d['observation_ids']), 422, 'Observação fora do escopo da visita.');
            foreach ($ids as $id) {
                DB::table('observation_reads')->insertOrIgnore(['observation_id' => $id, 'visit_id' => $v->id, 'user_id' => $r->user()->id, 'read_at' => now()]);
            }
            $this->access->audit($r->user(), $e, 'observations.read', $v);

            return ['message' => 'Leitura registrada.'];
        });
    }
}
