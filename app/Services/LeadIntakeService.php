<?php

namespace App\Services;

use App\Models\DuplicateFlag;
use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use App\Services\LeadImport\ImportAction;
use Illuminate\Support\Facades\DB;

class LeadIntakeService
{
    public const WALK_IN_LABEL = 'Walk-in / Self';

    /**
     * Determine what action would be taken for the given payload, without writing anything.
     *
     * Returns an ImportAction with one of: CREATE, MERGE, FLAG, REJECT.
     */
    public function preview(array $data): ImportAction
    {
        $phone = $this->normalizePhone($data['phone'] ?? null);
        if ($phone === null || $phone === '') {
            return ImportAction::reject($data, 'phone missing or unparseable');
        }

        $ownerName    = $this->trimOrNull($data['owner_name']    ?? null);
        $referrerName = $this->trimOrNull($data['referrer_name'] ?? null);
        [$ownerId, $referrerId] = $this->resolveOwnership($ownerName, $referrerName);
        // Pre-refactor `resolveDuplicate()` passed null for $referrerName in the merge/flag
        // paths. That difference is unreachable: MERGE requires incoming tier > existing tier,
        // and FLAG requires both to be head-tier — both cases demand a non-null owner_name,
        // which makes $ownerName the winning branch in deriveLeadSource() regardless of referrer.
        $leadSource = $this->deriveLeadSource($data, $ownerName, $referrerName);
        $mapped = $this->buildStudentAttributes($data, $phone, $ownerId, $referrerId, $leadSource);

        $existing = Student::where('phone', $phone)->first();
        if ($existing === null) {
            return ImportAction::create($mapped);
        }

        $incomingTier = LeadPriority::tierByName($ownerName);
        $existingTier = LeadPriority::tier($existing->owner);

        if (LeadPriority::isHeadTier($incomingTier)
            && LeadPriority::isHeadTier($existingTier)
            && $ownerId !== null
            && $existing->owner_id !== null
            && $existing->owner_id !== $ownerId
        ) {
            return ImportAction::flag($mapped, $existing->id);
        }

        if ($incomingTier > $existingTier) {
            return ImportAction::merge($mapped, $existing->id);
        }

        return ImportAction::reject($mapped, 'duplicate of existing student', $existing->id);
    }

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
        $decision = $this->preview($data);

        return match ($decision->action) {
            ImportAction::CREATE => ['student' => DB::transaction(fn () => Student::create($decision->mappedPayload))],
            ImportAction::MERGE  => $this->executeMerge($decision),
            ImportAction::FLAG   => $this->executeFlag($decision),
            ImportAction::REJECT => ['duplicate' => true, 'existing_id' => $decision->existingStudentId],
        };
    }

    private function executeMerge(ImportAction $decision): array
    {
        return DB::transaction(function () use ($decision) {
            $existing = Student::findOrFail($decision->existingStudentId);
            $existing->phone = '__DEMOTED_'.$existing->id;
            $existing->saveQuietly();

            $new = Student::create($decision->mappedPayload);
            $this->reparentChildren($existing, $new);
            $demotedId = $existing->id;
            $existing->delete();
            return ['student' => $new, 'demoted_existing_id' => $demotedId];
        });
    }

    private function executeFlag(ImportAction $decision): array
    {
        return DB::transaction(function () use ($decision) {
            $attrs = $decision->mappedPayload;
            $attrs['flagged_for_review'] = true;
            $attrs['flag_reason'] = DuplicateFlag::REASON_HEAD_OWNERSHIP;
            $new = Student::create($attrs);

            $existing = Student::findOrFail($decision->existingStudentId);
            $existing->flagged_for_review = true;
            $existing->flag_reason = DuplicateFlag::REASON_HEAD_OWNERSHIP;
            $existing->save();

            $flag = DuplicateFlag::create([
                'phone'        => $new->phone,
                'student_a_id' => $existing->id,
                'student_b_id' => $new->id,
                'reason'       => DuplicateFlag::REASON_HEAD_OWNERSHIP,
            ]);
            return ['student' => $new, 'flag' => $flag];
        });
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
