<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ElderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:200', 'birth_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'medical_information' => 'nullable|string|max:10000', 'timezone' => 'required|timezone', ...($this->isMethod('PUT') ? ['version' => 'required|integer|min:1', 'active' => 'sometimes|boolean'] : [])];
    }
}
