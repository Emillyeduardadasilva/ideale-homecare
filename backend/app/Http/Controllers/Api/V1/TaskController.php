<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskRequest;
use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Services\CareAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private CareAccess $access) {}

    public function index(Request $r, Elder $elder)
    {
        $this->access->check($r->user(), $elder);

        return Task::with(['assignees', 'completion'])->where('elder_id', $elder->id)->orderByDesc('due_at')->paginate(30);
    }

    public function store(TaskRequest $r, Elder $elder)
    {
        return $this->save($r, $elder);
    }

    public function update(TaskRequest $r, Elder $elder, int $task)
    {
        return $this->save($r, $elder, $task);
    }

    private function save(TaskRequest $r, Elder $elder, ?int $id = null)
    {
        $d = $r->validated();

        return $this->access->write($r->user(), $elder, 'family', function (Elder $e) use ($r, $d, $id) {
            $valid = CareMembership::where('elder_id', $e->id)->where('active', true)->whereIn('role', ['manager', 'relative'])->whereIn('user_id', $d['assignees'])->count();
            abort_unless($valid === count($d['assignees']), 422, 'Responsável sem vínculo familiar ativo.');
            $values = ['title' => $d['title'], 'description' => $d['description'] ?? null, 'due_at' => CarbonImmutable::parse($d['due_at'])->utc()];
            if ($id) {
                $t = Task::where('elder_id', $e->id)->findOrFail($id);
                $this->access->version($t, $d['version']);
                abort_unless($t->status === 'pending', 409, 'Tarefa não está pendente.');
                $t->update([...$values, 'version' => $t->version + 1]);
            } else {
                $t = Task::create([...$values, 'elder_id' => $e->id, 'created_by' => $r->user()->id]);
            }
            $t->assignees()->sync($d['assignees']);
            $this->access->audit($r->user(), $e, $id ? 'task.updated' : 'task.created', $t);

            return response()->json(['data' => $t->load(['assignees', 'completion'])], $id ? 200 : 201);
        });
    }

    public function complete(Request $r, Elder $elder, int $task)
    {
        return $this->access->write($r->user(), $elder, 'confirm', function (Elder $e) use ($r, $task) {
            $t = Task::where('elder_id', $e->id)->findOrFail($task);
            abort_unless($e->user_id === $r->user()->id || $t->assignees()->where('users.id', $r->user()->id)->exists(), 403);
            if ($t->status === 'completed') {
                return ['data' => $t->load('completion')];
            }
            abort_unless($t->status === 'pending', 409);
            TaskCompletion::create(['task_id' => $t->id, 'user_id' => $r->user()->id, 'completed_at' => now()]);
            $t->update(['status' => 'completed', 'version' => $t->version + 1]);
            $this->access->audit($r->user(), $e, 'task.completed', $t);

            return ['data' => $t->load('completion')];
        });
    }

    public function cancel(Request $r, Elder $elder, int $task)
    {
        $d = $r->validate(['version' => 'required|integer']);

        return $this->access->write($r->user(), $elder, 'family', function (Elder $e) use ($r, $task, $d) {
            $t = Task::where('elder_id', $e->id)->findOrFail($task);
            $this->access->version($t, $d['version']);
            abort_unless($t->status === 'pending', 409);
            $t->update(['status' => 'cancelled', 'version' => $t->version + 1]);
            $this->access->audit($r->user(), $e, 'task.cancelled', $t);

            return ['data' => $t];
        });
    }
}
