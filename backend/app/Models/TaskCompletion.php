<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskCompletion extends Model
{
    protected $table = 'task_completions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }
}
