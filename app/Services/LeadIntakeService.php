<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeadIntakeService
{
    public const WALK_IN_LABEL = 'Walk-in / Self';

    /**
     * Ingest a normalized lead payload.
     *
     * Returns either:
     *   ['duplicate' => true, 'existing_id' => int]
     *   ['student' => Student]
     */
    public function ingest(array $data): array
    {
        $phone = $this->normalizePhone($data['phone'] ?? null);

        $existing = Student::where('phone', $phone)->first();
        if ($existing !== null) {
            return ['duplicate' => true, 'existing_id' => $existing->id];
        }

        $ownerName    = $this->trimOrNull($data['owner_name']    ?? null);
        $referrerName = $this->trimOrNull($data['referrer_name'] ?? null);

        [$ownerId, $referrerId] = $this->resolveOwnership($ownerName, $referrerName);

        $leadSource = $this->trimOrNull($data['source'] ?? null)
            ?? ($ownerName !== null ? 'Sheet:' . $ownerName : ($referrerName ?? self::WALK_IN_LABEL));

        $student = DB::transaction(fn () => Student::create([
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
        ]));

        return ['student' => $student];
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
