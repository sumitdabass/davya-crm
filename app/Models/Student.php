<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Student extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = [];

    public function scopeStuck(Builder $query): Builder
    {
        return $query
            ->where('updated_at', '<', now()->subDays(14))
            ->whereNotIn('stage', ['Admission Confirmed', 'Closed']);
    }

    public function scopeWithExpectedProfit(Builder $query): Builder
    {
        return $query
            ->select('students.*')
            ->selectRaw('students.deal_amount - COALESCE((SELECT SUM(amount) FROM payouts WHERE payouts.student_id = students.id), 0) AS expected_profit');
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->hasRole('admin')) {
            return $query;
        }

        // Heads and their team members share the same visibility — a team acts as one unit.
        // Freelancers are restricted to what they personally own or referred.
        if ($user->hasRole('head') || $user->hasRole('member')) {
            $headId = $user->hasRole('head') ? $user->id : ($user->team_head_id ?? $user->id);
            $teamIds = User::where('team_head_id', $headId)->pluck('id')->toArray();
            $teamIds[] = $headId;

            $teamNames = User::whereIn('id', $teamIds)->pluck('name')->toArray();
            $teamLeadSources = array_merge(
                $teamNames,
                array_map(fn ($n) => 'Sheet:'.$n, $teamNames),
            );

            return $query->where(fn ($q) => $q
                ->whereIn('owner_id', $teamIds)
                ->orWhereIn('referrer_id', $teamIds)
                ->orWhereIn('lead_source', $teamLeadSources));
        }

        // Freelancer — strictly own.
        return $query->where(fn ($q) => $q
            ->where('owner_id', $user->id)
            ->orWhere('referrer_id', $user->id));
    }

    protected $casts = [
        // MySQL returns FK columns as strings; Laravel auto-casts primary keys
        // to int, so `$student->owner_id === $user->id` fails silently without
        // these casts. Matters for StudentPolicy::view and scopeVisibleTo.
        'owner_id' => 'integer',
        'referrer_id' => 'integer',
        'deal_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'is_ipu_registered' => 'boolean',
        'seat_fee_due' => 'boolean',
        'address_sent' => 'boolean',
        'office_visit' => 'boolean',
        'meeting_date' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function roundHistory(): HasMany
    {
        return $this->hasMany(RoundHistory::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(StudentNote::class)->latest();
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(StudentFieldValue::class);
    }

    public function latestAdmittedRound(): HasOne
    {
        return $this->hasOne(RoundHistory::class)
            ->where('seat_fee_paid', true)
            ->latestOfMany('fee_paid_at');
    }

    public function getTotalReceivedAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function getPendingAmountAttribute(): float
    {
        return (float) ($this->deal_amount ?? 0) - $this->total_received;
    }

    public function getTotalPayoutsAttribute(): float
    {
        return (float) $this->payouts()->sum('amount');
    }

    public function getPayoutsPaidAttribute(): float
    {
        return (float) $this->payouts()->where('status', 'paid')->sum('amount');
    }

    public function getPayoutsOutstandingAttribute(): float
    {
        return $this->total_payouts - $this->payouts_paid;
    }

    public function getExpectedProfitAttribute(): float
    {
        if (array_key_exists('expected_profit', $this->attributes)) {
            return (float) $this->attributes['expected_profit'];
        }

        return (float) ($this->deal_amount ?? 0) - $this->total_payouts;
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Humanized logging is done explicitly via ActivityDescriber; suppress Spatie auto-log.
        return LogOptions::defaults()->logOnly([])->dontSubmitEmptyLogs();
    }
}
