<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CareNotification;
use App\Models\Elder;
use App\Models\PushSubscription;
use App\Services\CareAccess;
use App\Services\PushEndpoint;
use App\Services\PushSubscriptions;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private CareAccess $access) {}

    public function index(Request $r)
    {
        $data = $r->validate(['after_id' => 'sometimes|integer|min:0']);
        $query = CareNotification::where('user_id', $r->user()->id)->whereIn('elder_id', $this->access->scope(Elder::query(), $r->user())->select('id'))->with('reminder:id,source_type,source_id,due_at,status');
        $page = array_key_exists('after_id', $data)
            ? $query->where('id', '>', $data['after_id'])->orderBy('id')->paginate(30)
            : $query->orderByDesc('id')->paginate(30);

        return [...$page->toArray(), 'next_after_id' => array_key_exists('after_id', $data) ? ($page->getCollection()->last()?->id ?? (int) $data['after_id']) : ($page->getCollection()->first()?->id ?? 0)];
    }

    public function read(Request $r, int $notification)
    {
        $n = CareNotification::where('user_id', $r->user()->id)->findOrFail($notification);

        return $this->access->write($r->user(), Elder::findOrFail($n->elder_id), 'view', function () use ($n) {
            if (! $n->read_at) {
                $n->update(['read_at' => now()]);
            }

            return ['data' => $n];
        });
    }

    public function key()
    {
        return ['public_key' => config('care.push.public_key')];
    }

    public function subscribe(Request $r, PushSubscriptions $subscriptions)
    {
        $d = $r->validate(['endpoint' => ['required', 'url', 'max:2048', function ($attribute, $value, $fail) {
            if (! PushEndpoint::allowed($value)) {
                $fail('Serviço push não permitido.');
            }
        }],
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{87}={0,1}$/'], 'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{22}={0,2}$/']]);
        $s = $subscriptions->save($r->user(), $d);

        return response()->json(['data' => ['id' => $s->id]], 201);
    }

    public function unsubscribe(Request $r, int $subscription)
    {
        PushSubscription::where('user_id', $r->user()->id)->findOrFail($subscription)->delete();

        return response()->noContent();
    }
}
