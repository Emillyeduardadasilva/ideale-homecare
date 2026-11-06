<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MedicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:200', 'dosage' => 'required|string|max:200', 'instructions' => 'nullable|string|max:5000',
            'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on', 'times' => 'required|array|min:1|max:24',
            'times.*' => 'required|date_format:H:i|distinct', ...($this->isMethod('PUT') ? ['version' => 'required|integer|min:1', 'active' => 'required|boolean'] : [])];
    }
}
