<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Payment $p) {
            if ($p->type === 'refund' && $p->amount > 0) {
                $p->amount = -abs($p->amount);
            }
            if ($p->type !== 'refund' && $p->amount < 0) {
                $p->amount = abs($p->amount);
            }
        });
    }
}
