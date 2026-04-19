<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoundHistory extends Model
{
    use HasFactory;

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

    public function scopeSeatFeePending(Builder $query): Builder
    {
        return $query
            ->where('outcome', 'Allotted — Fee Pending')
            ->where('seat_fee_paid', false);
    }

    public function scopeReEntryCandidates(Builder $query): Builder
    {
        // One row per student: the latest round_history row, where that latest is "Kicked Out — Fee Unpaid".
        $latestIds = self::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('student_id');

        return $query
            ->whereIn('id', $latestIds)
            ->where('outcome', 'Kicked Out — Fee Unpaid');
    }
}
