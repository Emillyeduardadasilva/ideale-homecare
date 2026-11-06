<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CareShiftRequest;
use App\Http\Requests\ContactRequest;
use App\Models\CareShift;
use App\Models\Elder;
use App\Models\EmergencyContact;
use App\Services\CareAccess;
use App\Services\CareShiftService;
use App\Services\QuickCall;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(private CareAccess $access, private CareShiftService $shifts) {}

    public function index(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder);

        return ['data' => EmergencyContact::where('elder_id', $elder->id)->orderBy('priority')->get()];
    }

    public function store(ContactRequest $r, Elder $elder)
    {
        return $this->save($r, $elder);
    }

    public function update(ContactRequest $r, Elder $elder, int $contact)
    {
        return $this->save($r, $elder, $contact);
    }

    private function save(ContactRequest $r, Elder $elder, ?int $id = null)
    {
        $d = $r->validated();

        return $this->access->write($r->user(), $elder, 'manage', function (Elder $e) use ($r, $d, $id) {
            if ($id) {
                $c = EmergencyContact::where('elder_id', $e->id)->findOrFail($id);
                $this->access->version($c, $d['version']);
                $c->update([...$d, 'version' => $c->version + 1]);
            } else {
                $c = EmergencyContact::create([...$d, 'elder_id' => $e->id]);
            }
            $this->access->audit($r->user(), $e, $id ? 'contact.updated' : 'contact.created', $c);

            return response()->json(['data' => $c], $id ? 200 : 201);
        });
    }

    public function shifts(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder);

        return CareShift::where('elder_id', $elder->id)->orderByDesc('starts_at')->paginate(30);
    }

    public function shift(CareShiftRequest $r, Elder $elder)
    {
        return response()->json(['data' => $this->shifts->save($r->user(), $elder, $r->validated())], 201);
    }

    public function updateShift(CareShiftRequest $r, Elder $elder, int $shift)
    {
        return ['data' => $this->shifts->save($r->user(), $elder, $r->validated(), $shift)];
    }

    public function cancelShift(Request $r, Elder $elder, int $shift)
    {
        $data = $r->validate(['version' => 'required|integer|min:1']);
        $this->shifts->cancel($r->user(), $elder, $shift, $data['version']);

        return response()->noContent();
    }

    public function current(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder);
        $contact = app(QuickCall::class)->resolve($elder);
        abort_unless($contact, 404, 'Nenhum contato disponível.');

        return ['data' => $contact];
    }
}
