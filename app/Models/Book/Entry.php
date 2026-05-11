<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Entry extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'book_entries';

    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'section_id',
        'title',
        'salary_amount',
        'loan_amount',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'salary_amount' => 'decimal:2',
        'loan_amount' => 'decimal:2',
    ];

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
}
