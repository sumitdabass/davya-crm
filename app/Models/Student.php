<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Student extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'ipu_password' => 'encrypted',
        'deal_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'is_ipu_registered' => 'boolean',
        'seat_fee_due' => 'boolean',
        'address_sent' => 'boolean',
        'office_visit' => 'boolean',
        'admission_date' => 'date',
        'meeting_date' => 'datetime',
    ];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function referrer(): BelongsTo { return $this->belongsTo(User::class, 'referrer_id'); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function roundHistory(): HasMany { return $this->hasMany(RoundHistory::class); }

    public function getTotalReceivedAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function getPendingAmountAttribute(): float
    {
        return (float) ($this->deal_amount ?? 0) - $this->total_received;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }
}
