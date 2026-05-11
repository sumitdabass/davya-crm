<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Entry extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'book_entries';

    public const FREQUENCIES = [
        'one_time' => 'One-time',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly' => 'Yearly',
    ];

    public const PERIODS_PER_YEAR = [
        'one_time' => 1, 'daily' => 365, 'weekly' => 52,
        'monthly' => 12, 'quarterly' => 4, 'yearly' => 1,
    ];

    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'section_id',
        'title',
        'salary_amount',
        'loan_amount',
        'frequency',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'salary_amount' => 'decimal:2',
        'loan_amount' => 'decimal:2',
    ];

    protected $attributes = [
        'frequency' => 'one_time',
    ];

    protected static function booted(): void
    {
        static::saving(function (Entry $e) {
            $fy = FiscalYear::find($e->fiscal_year_id);
            if ($fy && $fy->is_closed && $e->isDirty(['salary_amount', 'loan_amount', 'title', 'notes', 'section_id'])) {
                throw new \DomainException("Cannot edit entry — FY {$fy->label} is closed");
            }
            if ($fy && $fy->is_closed && ! $e->exists) {
                throw new \DomainException("Cannot create entry — FY {$fy->label} is closed");
            }
        });
        static::saving(function (Entry $e) {
            if ($e->frequency && ! array_key_exists($e->frequency, self::FREQUENCIES)) {
                throw new \InvalidArgumentException("Invalid frequency: {$e->frequency}");
            }
        });
        static::deleting(function (Entry $e) {
            $fy = FiscalYear::find($e->fiscal_year_id);
            if ($fy && $fy->is_closed) {
                throw new \DomainException("Cannot delete entry — FY {$fy->label} is closed");
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

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function getIsLoanAttribute(): bool
    {
        return (float) $this->loan_amount > 0;
    }

    public function getPeriodsPerYearAttribute(): int
    {
        return self::PERIODS_PER_YEAR[$this->frequency ?? 'one_time'] ?? 1;
    }

    public function getAnnualizedSalaryAmountAttribute(): float
    {
        return (float) $this->salary_amount * $this->periods_per_year;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EntryPayment::class, 'entry_id');
    }

    public function getPaidAttribute(): string
    {
        return (string) $this->payments()->where('direction', 'out')->sum('amount');
    }

    public function getReceivedBackAttribute(): string
    {
        return (string) $this->payments()->where('direction', 'in')->sum('amount');
    }

    public function getBalanceAttribute(): string
    {
        return (string) (
            (float) $this->salary_amount + (float) $this->loan_amount
            - (float) $this->paid - (float) $this->received_back
        );
    }

    public function getLoanOutstandingAttribute(): string
    {
        return (string) ((float) $this->loan_amount - (float) $this->received_back);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    protected static function newFactory()
    {
        return \Database\Factories\Book\EntryFactory::new();
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
