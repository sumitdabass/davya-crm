<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    public function scopeStuck(Builder $query): Builder
    {
        return $query
            ->where('updated_at', '<', now()->subDays(14))
            ->whereNotIn('stage', ['Admission Confirmed', 'Closed']);
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
            $adminId = User::role('admin')->value('id');

            return $query->where(fn ($q) => $q
                ->whereIn('owner_id', $teamIds)
                ->orWhereIn('referrer_id', $teamIds)
                // Unallocated admin pool (owner=admin, no human referrer) is shared
                // across all heads + their teams — e.g. backfilled Sheet:Sumit leads.
                ->orWhere(fn ($qq) => $qq
                    ->where('owner_id', $adminId)
                    ->whereNull('referrer_id'))
                // Admin-owned leads whose lead_source names this team go to that team.
                ->orWhere(fn ($qq) => $qq
                    ->where('owner_id', $adminId)
                    ->where('referrer_id', $adminId)
                    ->whereIn('lead_source', $teamLeadSources)));
        }

        // Freelancer — strictly own.
        return $query->where(fn ($q) => $q
            ->where('owner_id', $user->id)
            ->orWhere('referrer_id', $user->id));
    }

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
    public function notes(): HasMany { return $this->hasMany(StudentNote::class)->latest(); }

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
