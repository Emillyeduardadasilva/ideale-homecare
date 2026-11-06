<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:200', 'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'], 'type' => 'required|in:family,central', 'priority' => 'required|integer|min:0|max:999', 'active' => 'sometimes|boolean', ...($this->isMethod('PUT') ? ['version' => 'required|integer|min:1'] : [])];
    }
}
