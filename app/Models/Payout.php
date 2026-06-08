<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
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
        static::saving(function (Payout $p) {
            $p->amount = abs((float) $p->amount);
            if ($p->status === 'paid' && $p->paid_at === null) {
                $p->paid_at = now();
            }
            if ($p->status === 'to_pay') {
                $p->paid_at = null;
            }
        });
    }
}
