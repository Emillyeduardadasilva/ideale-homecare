<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['text' => 'required|string|max:10000', 'occurred_at' => 'required|iso_instant|before_or_equal:now', 'tags' => 'nullable|array|max:10', 'tags.*' => 'string|max:50|distinct', 'corrects_id' => 'nullable|integer'];
    }
}
