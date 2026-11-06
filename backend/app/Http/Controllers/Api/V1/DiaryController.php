<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeasurementRequest;
use App\Http\Requests\ObservationRequest;
use App\Models\Elder;
use App\Models\Measurement;
use App\Models\Observation;
use App\Services\CareAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DiaryController extends Controller
{
    public function __construct(private CareAccess $access) {}

    public function index(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder);

        return Observation::where('elder_id', $elder->id)->orderByDesc('occurred_at')->orderByDesc('id')->paginate(30);
    }

    public function store(ObservationRequest $r, Elder $elder)
    {
        $d = $r->validated();

        return $this->access->write($r->user(), $elder, 'family', function (Elder $e) use ($d, $r) {
            if (! empty($d['corrects_id'])) {
                $original = Observation::where('elder_id', $e->id)->findOrFail($d['corrects_id']);
                abort_unless($original->user_id === $r->user()->id, 403, 'Somente o autor pode retificar sua observação.');
            }
            $o = Observation::create([...$d, 'elder_id' => $e->id, 'user_id' => $r->user()->id, 'occurred_at' => CarbonImmutable::parse($d['occurred_at'])->utc()]);
            $this->access->audit($r->user(), $e, 'observation.created', $o);

            return response()->json(['data' => $o], 201);
        });
    }

    public function measurements(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder);

        return Measurement::where('elder_id', $elder->id)->orderByDesc('measured_at')->paginate(30);
    }

    public function measure(MeasurementRequest $r, Elder $elder)
    {
        $d = $r->validated();

        return $this->access->write($r->user(), $elder, 'family', function (Elder $e) use ($d, $r) {
            $m = Measurement::create([...$d, 'elder_id' => $e->id, 'user_id' => $r->user()->id, 'measured_at' => CarbonImmutable::parse($d['measured_at'])->utc(),
                'unit' => match ($d['type']) {
                    'blood_pressure' => 'mmHg','glucose' => 'mg/dL','temperature' => '°C'
                }]);
            $this->access->audit($r->user(), $e, 'measurement.created', $m);

            return response()->json(['data' => $m], 201);
        });
    }
}
