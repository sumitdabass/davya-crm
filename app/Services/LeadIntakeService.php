<?php

namespace App\Services;

use App\Models\DuplicateFlag;
use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeadIntakeService
{
    public const WALK_IN_LABEL = 'Walk-in / Self';

    /**
     * Ingest a normalized lead payload.
     *
     * Returns one of:
     *   ['duplicate' => true, 'existing_id' => int]                 — rejected (same or higher priority exists)
     *   ['student' => Student, 'demoted_existing_id' => int]        — new beats existing; existing deleted, children re-parented
     *   ['student' => Student, 'flag' => DuplicateFlag]              — both are head-tier; admin review required
     *   ['student' => Student]                                       — plain insert, no duplicate
     */
    public function ingest(array $data): array
    {
        $phone = $this->normalizePhone($data['phone'] ?? null);

        $ownerName    = $this->trimOrNull($data['owner_name']    ?? null);
        $referrerName = $this->trimOrNull($data['referrer_name'] ?? null);

        [$ownerId, $referrerId] = $this->resolveOwnership($ownerName, $referrerName);

        $existing = Student::where('phone', $phone)->first();
        if ($existing !== null) {
            return $this->resolveDuplicate($existing, $ownerName, $ownerId, $referrerId, $data, $phone);
        }

        $leadSource = $this->deriveLeadSource($data, $ownerName, $referrerName);

        $student = DB::transaction(fn () => Student::create(
            $this->buildStudentAttributes($data, $phone, $ownerId, $referrerId, $leadSource)
        ));

        return ['student' => $student];
    }

    /**
     * Decide how to handle an incoming lead whose phone already exists.
     *
     * Rules:
     *   incoming tier > existing tier                            → demote existing, insert new, re-parent children
     *   incoming tier == existing tier (same head, same user)    → reject as plain duplicate
     *   incoming tier == existing tier == head-tier (different head) → insert flagged-for-review + create DuplicateFlag
     *   incoming tier < existing tier                            → reject as plain duplicate
     */
    private function resolveDuplicate(Student $existing, ?string $ownerName, ?int $ownerId, ?int $referrerId, array $data, ?string $phone): array
    {
        $incomingTier = LeadPriority::tierByName($ownerName);
        $existingOwner = $existing->owner;
        $existingTier = LeadPriority::tier($existingOwner);

        // Head-vs-head conflict (Sonam vs Nikhil in either order, different owners) → flag for admin.
        // Checked BEFORE the "higher tier demotes" rule because head conflicts are never auto-resolved.
        if (LeadPriority::isHeadTier($incomingTier)
            && LeadPriority::isHeadTier($existingTier)
            && $ownerId !== null
            && $existing->owner_id !== null
            && $existing->owner_id !== $ownerId
        ) {
            return DB::transaction(function () use ($existing, $data, $phone, $ownerId, $referrerId, $ownerName) {
                $leadSource = $this->deriveLeadSource($data, $ownerName, null);
                $attrs = $this->buildStudentAttributes($data, $phone, $ownerId, $referrerId, $leadSource);
                $attrs['flagged_for_review'] = true;
                $attrs['flag_reason'] = DuplicateFlag::REASON_HEAD_OWNERSHIP;
                $new = Student::create($attrs);

                // Also mark the existing one — admin resolves the pair together.
                $existing->flagged_for_review = true;
                $existing->flag_reason = DuplicateFlag::REASON_HEAD_OWNERSHIP;
                $existing->save();

                $flag = DuplicateFlag::create([
                    'phone'        => $phone,
                    'student_a_id' => $existing->id,
                    'student_b_id' => $new->id,
                    'reason'       => DuplicateFlag::REASON_HEAD_OWNERSHIP,
                ]);
                return ['student' => $new, 'flag' => $flag];
            });
        }

        // Non-head conflict where incoming beats existing → demote existing, re-parent children, insert new.
        // Covers Sumit-existing → Sonam/Nikhil-incoming.
        if ($incomingTier > $existingTier) {
            return DB::transaction(function () use ($existing, $data, $phone, $ownerId, $referrerId, $ownerName) {
                // Free the unique `phone` slot before inserting the new record.
                $existing->phone = '__DEMOTED_'.$existing->id;
                $existing->saveQuietly();

                $leadSource = $this->deriveLeadSource($data, $ownerName, null);
                $new = Student::create(
                    $this->buildStudentAttributes($data, $phone, $ownerId, $referrerId, $leadSource)
                );
                $this->reparentChildren($existing, $new);
                $demotedId = $existing->id;
                $existing->delete();
                return ['student' => $new, 'demoted_existing_id' => $demotedId];
            });
        }

        // Everything else is a plain duplicate: reject the incoming payload.
        return ['duplicate' => true, 'existing_id' => $existing->id];
    }

    private function reparentChildren(Student $from, Student $to): void
    {
        Payment::where('student_id', $from->id)->update(['student_id' => $to->id]);
        StudentNote::where('student_id', $from->id)->update(['student_id' => $to->id]);
        RoundHistory::where('student_id', $from->id)->update(['student_id' => $to->id]);
    }

    private function deriveLeadSource(array $data, ?string $ownerName, ?string $referrerName): ?string
    {
        return $this->trimOrNull($data['source'] ?? null)
            ?? ($ownerName !== null ? 'Sheet:' . $ownerName : ($referrerName ?? self::WALK_IN_LABEL));
    }

    private function buildStudentAttributes(array $data, ?string $phone, ?int $ownerId, ?int $referrerId, ?string $leadSource): array
    {
        return [
            'phone'         => $phone,
            'name'          => $data['name']          ?? null,
            'father_name'   => $data['father_name']   ?? null,
            'phone_2'       => $this->normalizePhone($data['phone_2'] ?? null),
            'email'         => $data['email']         ?? null,
            'exam_appeared' => $data['exam_appeared'] ?? null,
            'twelfth_marks' => $data['twelfth_marks'] ?? null,
            'rank'          => $data['rank']          ?? null,
            'category'      => $data['category']      ?? null,
            'state'         => $data['state']         ?? null,
            'course'        => $data['course']        ?? null,
            'preference_r1' => $data['college']       ?? null,
            'extra_notes'   => $data['remarks']       ?? null,
            'description'   => $data['description']   ?? null,
            'owner_id'      => $ownerId,
            'referrer_id'   => $referrerId,
            'lead_source'   => $leadSource,
            'stage'         => 'Lead Captured',
        ];
    }

    private function resolveOwnership(?string $ownerName, ?string $referrerName): array
    {
        $owner = $this->findUserByName($ownerName);
        if ($owner !== null) {
            $referrer = $this->findUserByName($referrerName);
            return [$owner->id, $referrer?->id];
        }

        if ($referrerName === null || $referrerName === self::WALK_IN_LABEL) {
            return [$this->adminId(), null];
        }

        $referrer = $this->findUserByName($referrerName);
        if ($referrer === null) {
            return [$this->adminId(), null];
        }

        $ownerId = $referrer->team_head_id ?? $referrer->id;
        return [$ownerId, $referrer->id];
    }

    private function findUserByName(?string $name): ?User
    {
        if ($name === null || $name === '') {
            return null;
        }
        return User::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
    }

    private function adminId(): int
    {
        return User::role('admin')->firstOrFail()->id;
    }

    public function normalizePhone(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $v);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }

    private function trimOrNull(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
