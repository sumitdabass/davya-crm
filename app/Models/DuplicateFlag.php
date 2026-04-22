<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateFlag extends Model
{
    protected $guarded = [];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public const REASON_HEAD_OWNERSHIP = 'head_ownership';

    public function studentA(): BelongsTo { return $this->belongsTo(Student::class, 'student_a_id'); }
    public function studentB(): BelongsTo { return $this->belongsTo(Student::class, 'student_b_id'); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by_user_id'); }
    public function kept(): BelongsTo { return $this->belongsTo(Student::class, 'kept_student_id'); }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
