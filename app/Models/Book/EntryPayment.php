<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EntryPayment extends Model
{
    use HasFactory, SoftDeletes;

    public const DIRECTIONS = ['out', 'in'];

    public const MODES = ['cash', 'bank', 'upi', 'cheque', 'other'];

    protected $table = 'book_entry_payments';

    protected $fillable = [
        'entry_id',
        'occurred_on',
        'amount',
        'direction',
        'mode',
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
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Book\EntryPaymentFactory::new();
    }
}
