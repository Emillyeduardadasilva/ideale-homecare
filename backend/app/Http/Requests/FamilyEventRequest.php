<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FamilyEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['title' => 'required|string|max:200', 'description' => 'nullable|string|max:5000', 'starts_at' => 'required|iso_instant', 'ends_at' => 'required|iso_instant|after:starts_at', ...($this->isMethod('PUT') ? ['version' => 'required|integer|min:1', 'active' => 'required|boolean'] : [])];
    }
}
