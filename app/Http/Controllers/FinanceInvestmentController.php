<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvestmentRequest;
use App\Models\Investment;
use App\Models\LedgerEntry;
use App\Services\Finance\LedgerRoutingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinanceInvestmentController extends Controller
{
    public function store(StoreInvestmentRequest $request, LedgerRoutingService $routing): JsonResponse
    {
        $data = $request->validated();

        $existing = Investment::where('slack_message_id', $data['slack_message_id'])->first();
        if ($existing !== null) {
            return response()->json([
                'error' => 'duplicate_slack_message',
                'existing_id' => $existing->id,
            ], 409);
        }

        try {
            $result = DB::transaction(function () use ($data, $routing) {
                $inv = Investment::create([
                    'asset_name'       => $data['asset_name'],
                    'amount'           => $data['amount'],
                    'direction'        => $data['direction'],
                    'transacted_at'    => $data['transacted_at'] ?? now(),
                    'slack_message_id' => $data['slack_message_id'],
                    'raw_input'        => $data['raw_input'] ?? null,
                ]);
                $rows = $routing->routeInvestment($inv);
                foreach ($rows as $r) LedgerEntry::create($r);
                return ['investment' => $inv, 'ledger_count' => count($rows)];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                $existing = Investment::where('slack_message_id', $data['slack_message_id'])->first();
                if ($existing !== null) {
                    return response()->json([
                        'error'       => 'duplicate_slack_message',
                        'existing_id' => $existing->id,
                    ], 409);
                }
            }
            throw $e;
        }

        Log::info('finance.investment.captured', [
            'investment_id' => $result['investment']->id,
            'asset_name'    => $data['asset_name'],
            'direction'     => $data['direction'],
            'amount'        => $data['amount'],
            'slack_id'      => $data['slack_message_id'],
            'ledger_rows'   => $result['ledger_count'],
        ]);

        return response()->json([
            'id' => $result['investment']->id,
            'ledger_entries' => $result['ledger_count'],
        ], 201);
    }
}
