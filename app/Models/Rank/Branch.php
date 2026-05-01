<?php

namespace App\Models\Rank;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Branch extends Model
{
    protected $connection = 'ranks';

    protected $fillable = ['course_id', 'name', 'family'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
