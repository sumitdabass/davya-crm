<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EntryPayment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const DIRECTIONS = ['out', 'in'];

    public const MODES = ['cash', 'bank', 'upi', 'cheque', 'other'];

    protected $table = 'book_entry_payments';

    protected $fillable = [
        'entry_id',
        'occurred_on',
        'amount',
        'direction',
        'mode',
        'source',
        'reference',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'occurred_on' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (EntryPayment $p) {
            if (! in_array($p->direction, self::DIRECTIONS, true)) {
                throw new \InvalidArgumentException("Invalid direction: {$p->direction}");
            }
            if (! in_array($p->mode, self::MODES, true)) {
                throw new \InvalidArgumentException("Invalid mode: {$p->mode}");
            }
            $entry = Entry::find($p->entry_id);
            if ($entry) {
                $fy = FiscalYear::find($entry->fiscal_year_id);
                if ($fy && $fy->is_closed) {
                    throw new \DomainException("Cannot record payment — FY {$fy->label} is closed");
                }
            }
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    protected static function newFactory()
    {
        return \Database\Factories\Book\EntryPaymentFactory::new();
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
