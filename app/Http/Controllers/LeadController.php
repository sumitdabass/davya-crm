<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadRequest;
use App\Services\LeadIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LeadController extends Controller
{
    public function __construct(private LeadIntakeService $intake) {}

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $data   = $request->validated();
        $result = $this->intake->ingest($data);

        if ($result['duplicate'] ?? false) {
            return response()->json([
                'error'       => 'duplicate_phone',
                'existing_id' => $result['existing_id'],
            ], 409);
        }

        $student = $result['student'];

        Log::info('lead.captured', [
            'student_id'    => $student->id,
            'owner_id'      => $student->owner_id,
            'referrer_name' => $data['referrer_name'] ?? null,
            'owner_name'    => $data['owner_name']    ?? null,
        ]);

        return response()->json([
            'id'       => $student->id,
            'stage'    => $student->stage,
            'owner'    => $student->owner?->name,
            'referrer' => $student->referrer?->name,
        ], 201);
    }
}
