<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentFieldValue extends Model
{
    protected $fillable = [
        'student_id', 'student_field_id',
        'value_text', 'value_number', 'value_date', 'value_json',
    ];

    protected $casts = [
        'value_number' => 'decimal:4',
        'value_date' => 'date',
        'value_json' => 'array',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(StudentField::class, 'student_field_id');
    }
}
