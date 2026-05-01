<?php

namespace App\Models\Rank;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Seat extends Model
{
    protected $connection = 'ranks';

    protected $fillable = [
        'university_id', 'course_id', 'year',
        'institute_id', 'branch_id',
        'seat_count', 'source_note', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'seat_count' => 'integer',
    ];

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function institute(): BelongsTo
    {
        return $this->belongsTo(Institute::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
