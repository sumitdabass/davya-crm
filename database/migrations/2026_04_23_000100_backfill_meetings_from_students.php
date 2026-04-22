<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Creates one Meeting per student with a non-null meeting_date.
    // Past dates -> status='held' with held_at populated.
    // Future (or now) dates -> status='scheduled'.
    // Mode defaults to 'in_person' (safe default; counsellor can edit).
    // created_by_id = owner_id; fallback to Sumit (admin) if owner is null.

    public function up(): void
    {
        $fallbackCreator = DB::table('users')
            ->where('email', 'sumit@davya.local')
            ->value('id') ?? 1;

        DB::table('students')
            ->whereNotNull('meeting_date')
            ->orderBy('id')
            ->select(['id', 'owner_id', 'meeting_date'])
            ->chunkById(200, function ($rows) use ($fallbackCreator) {
                $now = Carbon::now();
                $inserts = [];
                foreach ($rows as $row) {
                    $scheduledAt = Carbon::parse($row->meeting_date);
                    $ownerId = $row->owner_id ?: $fallbackCreator;
                    $isPast = $scheduledAt->lt($now);
                    $inserts[] = [
                        'student_id' => $row->id,
                        'owner_id' => $ownerId,
                        'scheduled_at' => $scheduledAt,
                        'mode' => 'in_person',
                        'status' => $isPast ? 'held' : 'scheduled',
                        'notes' => null,
                        'outcome_notes' => null,
                        'held_at' => $isPast ? $scheduledAt : null,
                        'rescheduled_from_id' => null,
                        'created_by_id' => $ownerId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if (! empty($inserts)) {
                    DB::table('meetings')->insert($inserts);
                }
            });
    }

    public function down(): void
    {
        // Reverse is to delete all meetings that look like backfilled rows — but we
        // can't reliably distinguish them after the fact. Safe rollback is handled
        // at the create-table migration level; this migration's down() is a no-op.
    }
};
