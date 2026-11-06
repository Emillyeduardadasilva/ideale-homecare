<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['title' => 'required|string|max:200', 'description' => 'nullable|string|max:5000', 'due_at' => 'required|iso_instant', 'assignees' => 'required|array|min:1|max:30', 'assignees.*' => 'required|integer|distinct', ...($this->isMethod('PUT') ? ['version' => 'required|integer|min:1'] : [])];
    }
}
