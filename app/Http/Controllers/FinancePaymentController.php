<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFinancePaymentRequest;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\LedgerRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinancePaymentController extends Controller
{
    public function store(StoreFinancePaymentRequest $request, LedgerRoutingService $routing): JsonResponse
    {
        $data = $request->validated();

        $existing = Payment::where('slack_message_id', $data['slack_message_id'])->first();
        if ($existing !== null) {
            return response()->json([
                'error'       => 'duplicate_slack_message',
                'existing_id' => $existing->id,
            ], 409);
        }

        $student = Student::where('phone', $data['student_phone'])->first();
        if ($student === null) {
            if (empty($data['referrer_name'])) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => ['referrer_name' => ['Referrer is required for new students.']],
                ], 422);
            }
            [$referrerId, $ownerId] = $this->deriveOwnership($data['referrer_name']);
            if ($referrerId === null && strtolower($data['referrer_name']) !== 'walk-in / self') {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => ['referrer_name' => ["Unknown referrer '{$data['referrer_name']}'."]],
                ], 422);
            }
            $student = Student::create([
                'phone'       => $data['student_phone'],
                'name'        => $data['student_name'] ?? 'Pending — '.$data['student_phone'],
                'owner_id'    => $ownerId,
                'referrer_id' => $referrerId,
                'lead_source' => $data['referrer_name'],
                'stage'       => 'Lead Captured',
            ]);
        }

        $type = ($data['is_partial'] ?? false) ? 'partial' : 'full';

        $result = DB::transaction(function () use ($data, $student, $type, $routing) {
            $payment = Payment::create([
                'student_id'         => $student->id,
                'type'               => $type,
                'amount'             => $data['amount'],
                'received_at'        => $data['received_at'] ?? now(),
                'recorded_by_user_id'=> null,
                'slack_message_id'   => $data['slack_message_id'],
                'raw_input'          => $data['raw_input'] ?? null,
            ]);

            $ledger = $routing->routePayment($payment);
            foreach ($ledger as $row) {
                LedgerEntry::create($row);
            }

            return ['payment' => $payment, 'ledger_count' => count($ledger)];
        });

        Log::info('finance.payment.captured', [
            'payment_id'  => $result['payment']->id,
            'student_id'  => $student->id,
            'slack_id'    => $data['slack_message_id'],
            'ledger_rows' => $result['ledger_count'],
        ]);

        return response()->json([
            'id'             => $result['payment']->id,
            'ledger_entries' => $result['ledger_count'],
        ], 201);
    }

    /**
     * Same logic as LeadController::deriveOwnership. Duplicated intentionally —
     * pulling it into a trait/service isn't justified by a single extra caller.
     *
     * @return array{0: ?int, 1: int}
     */
    private function deriveOwnership(string $referrerName): array
    {
        if (strtolower($referrerName) === 'walk-in / self') {
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
