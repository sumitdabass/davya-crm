<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeadController extends Controller
{
    private const WALK_IN_LABEL = 'Walk-in / Self';

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$referrerId, $ownerId] = $this->deriveOwnership($data['referrer_name']);

        $existing = Student::where('phone', $data['phone'])->first();
        if ($existing !== null) {
            return response()->json([
                'error'       => 'duplicate_phone',
                'existing_id' => $existing->id,
            ], 409);
        }

        $student = DB::transaction(fn () => Student::create([
            'phone'         => $data['phone'],
            'name'          => $data['name'],
            'father_name'   => $data['father_name']   ?? null,
            'phone_2'       => $data['phone_2']       ?? null,
            'exam_appeared' => $data['exam_appeared'] ?? null,
            'twelfth_marks' => $data['twelfth_marks'] ?? null,
            'category'      => $data['category']      ?? null,
            'course'        => $data['course']        ?? null,
            'description'   => $data['description']   ?? null,
            'owner_id'      => $ownerId,
            'referrer_id'   => $referrerId,
            'lead_source'   => $data['referrer_name'],
            'stage'         => 'Lead Captured',
        ]));

        Log::info('lead.captured', [
            'student_id'    => $student->id,
            'referrer_name' => $data['referrer_name'],
            'owner_id'      => $ownerId,
        ]);

        return response()->json([
            'id'       => $student->id,
            'stage'    => $student->stage,
            'owner'    => $student->owner?->name,
            'referrer' => $student->referrer?->name,
        ], 201);
    }

    private function deriveOwnership(string $referrerName): array
    {
        if ($referrerName === self::WALK_IN_LABEL) {
            return [null, $this->adminId()];
        }

        $referrer = User::whereRaw('LOWER(name) = ?', [strtolower($referrerName)])->first();

        if ($referrer === null) {
            return [null, $this->adminId()];
        }

        $ownerId = $referrer->team_head_id ?? $referrer->id;

        return [$referrer->id, $ownerId];
    }

    private function adminId(): int
    {
        return User::role('admin')->firstOrFail()->id;
    }
}
