<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    protected $table = 'tasks';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'version' => 1];

    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->select('users.id', 'users.name');
    }

    public function completion(): HasOne
    {
        return $this->hasOne(TaskCompletion::class);
    }
}
