<?php

namespace App\Models\Rank;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cutoff extends Model
{
    use SoftDeletes;

    protected $connection = 'ranks';

    protected $fillable = [
        'university_id', 'course_id', 'qualifying_exam_id', 'admission_process_id',
        'year', 'round', 'institute_id', 'branch_id', 'shift', 'region',
        'min_rank', 'max_rank', 'source', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'min_rank' => 'integer',
        'max_rank' => 'integer',
    ];

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function qualifyingExam(): BelongsTo
    {
        return $this->belongsTo(QualifyingExam::class);
    }

    public function admissionProcess(): BelongsTo
    {
        return $this->belongsTo(AdmissionProcess::class);
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
