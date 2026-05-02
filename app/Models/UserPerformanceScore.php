<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPerformanceScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'period_start',
        'period_end',
        'score',
        'tier',
        'signal_breakdown',
        'team_max_snapshot',
        'calculated_at',
    ];

    protected $casts = [
        'period_start'      => 'date',
        'period_end'        => 'date',
        'score'             => 'integer',
        'signal_breakdown'  => 'array',
        'team_max_snapshot' => 'array',
        'calculated_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
