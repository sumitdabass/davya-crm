<?php

namespace App\Models\Rank;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $connection = 'ranks';

    protected $fillable = ['university_id', 'name', 'code'];

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
