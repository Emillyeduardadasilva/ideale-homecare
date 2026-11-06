<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['type' => 'required|in:blood_pressure,glucose,temperature', 'value' => 'required|numeric|gt:0|max:9999',
            'secondary_value' => [Rule::requiredIf($this->input('type') === 'blood_pressure'), Rule::prohibitedIf($this->input('type') !== 'blood_pressure'), 'nullable', 'numeric', 'gt:0', 'max:9999'],
            'measured_at' => 'required|iso_instant|before_or_equal:now'];
    }
}
