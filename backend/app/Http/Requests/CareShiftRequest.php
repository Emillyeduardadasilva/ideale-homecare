<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CareShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['emergency_contact_id' => 'required|integer', 'starts_at' => 'required|iso_instant', 'ends_at' => 'required|iso_instant|after:starts_at', ...($this->isMethod('PUT') ? ['version' => 'required|integer|min:1'] : [])];
    }
}
