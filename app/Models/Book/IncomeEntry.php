<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class IncomeEntry extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'book_income_entries';

    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'occurred_on',
        'source',
        'amount',
        'notes',
    ];

    protected $casts = [
        'occurred_on' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (IncomeEntry $i) {
            $fy = FiscalYear::find($i->fiscal_year_id);
            if ($fy && $fy->is_closed) {
                throw new \DomainException("Cannot edit income — FY {$fy->label} is closed");
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Book\IncomeEntryFactory::new();
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
