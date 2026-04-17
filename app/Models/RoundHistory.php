<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoundHistory extends Model
{
    protected $table = 'round_history';

    protected $guarded = [];

    protected $casts = [
        'seat_fee_paid' => 'boolean',
        'fee_paid_at' => 'datetime',
        'seat_fee_amount' => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
