<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FinanceEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceEntryController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['sometimes', Rule::in(['income', 'expense'])],
        ]);

        $query = FinanceEntry::where('user_id', $request->user()->id)->orderByDesc('due_date')->orderByDesc('id');
        if (isset($filters['from'])) $query->whereDate('due_date', '>=', $filters['from']);
        if (isset($filters['to'])) $query->whereDate('due_date', '<=', $filters['to']);
        if (isset($filters['type'])) $query->where('type', $filters['type']);

        return $query->paginate(100);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $entry = FinanceEntry::create([...$data, 'user_id' => $request->user()->id]);

        return response()->json(['data' => $entry], 201);
    }

    public function update(Request $request, int $entry)
    {
        $financeEntry = FinanceEntry::where('user_id', $request->user()->id)->findOrFail($entry);
        $financeEntry->fill($request->validate($this->rules(false)))->save();

        return ['data' => $financeEntry->fresh()];
    }

    public function destroy(Request $request, int $entry)
    {
        FinanceEntry::where('user_id', $request->user()->id)->findOrFail($entry)->delete();

        return response()->noContent();
    }

    private function rules(bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'description' => [$presence, 'string', 'max:160'],
            'amount' => [$presence, 'numeric', 'gt:0', 'max:9999999999.99'],
            'type' => [$presence, Rule::in(['income', 'expense'])],
            'category' => [$presence, 'string', 'max:80'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => [$presence, Rule::in(['pending', 'paid'])],
        ];
    }
}
