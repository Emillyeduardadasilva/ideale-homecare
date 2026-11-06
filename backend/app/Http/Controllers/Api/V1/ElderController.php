<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ElderRequest;
use App\Http\Resources\ElderResource;
use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\Medication;
use App\Services\CareAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ElderController extends Controller
{
    public function __construct(private CareAccess $access) {}

    public function index(Request $r)
    {
        return ElderResource::collection($this->access->scope(Elder::query(), $r->user())->orderBy('name')->paginate(30));
    }

    public function store(ElderRequest $r)
    {
        abort_unless($r->user()->role === 'family', 403);
        $d = $r->validated();
        $elder = DB::transaction(function () use ($r, $d) {
            $e = Elder::create([...$d, 'created_by' => $r->user()->id]);
            CareMembership::create(['elder_id' => $e->id, 'user_id' => $r->user()->id, 'role' => 'manager']);
            $this->access->audit($r->user(), $e, 'elder.created', $e);

            return $e;
        });

        return (new ElderResource($elder))->response()->setStatusCode(201);
    }

    public function show(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder);

        return new ElderResource($elder);
    }

    public function update(ElderRequest $r, Elder $elder)
    {
        $d = $r->validated();

        return $this->access->write($r->user(), $elder, 'manageProfile', function (Elder $e) use ($r, $d) {
            $this->access->version($e, $d['version']);
            abort_if($d['timezone'] !== $e->timezone && Medication::where('elder_id', $e->id)->exists(), 422, 'O fuso não pode ser alterado após cadastrar medicação.');
            $e->update([...$d, 'version' => $e->version + 1]);
            $this->access->audit($r->user(), $e, 'elder.updated', $e);

            return new ElderResource($e);
        });
    }
}
