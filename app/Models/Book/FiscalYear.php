<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FiscalYear extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'book_fiscal_years';

    protected $fillable = [
        'company_id',
        'start_date',
        'end_date',
        'label',
        'is_closed',
        'closing_summary_json',
    ];

    protected $attributes = [
        'is_closed' => false,
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_closed' => 'bool',
        'closing_summary_json' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getClosingSummaryAttribute(): ?array
    {
        return $this->closing_summary_json;
    }

    protected static function newFactory()
    {
        return \Database\Factories\Book\FiscalYearFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('books');
    }
}
