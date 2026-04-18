<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use App\Models\LedgerEntry;
use App\Services\Finance\LedgerRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinanceExpenseController extends Controller
{
    public function store(StoreExpenseRequest $request, LedgerRoutingService $routing): JsonResponse
    {
        $data = $request->validated();

        $existing = Expense::where('slack_message_id', $data['slack_message_id'])->first();
        if ($existing !== null) {
            return response()->json([
                'error' => 'duplicate_slack_message',
                'existing_id' => $existing->id,
            ], 409);
        }

        $result = DB::transaction(function () use ($data, $routing) {
            $expense = Expense::create([
                'amount'           => $data['amount'],
                'category'         => $data['category']    ?? null,
                'description'      => $data['description'] ?? null,
                'paid_at'          => $data['paid_at']     ?? now(),
                'slack_message_id' => $data['slack_message_id'],
                'raw_input'        => $data['raw_input']   ?? null,
            ]);
            $rows = $routing->routeExpense($expense);
            foreach ($rows as $r) LedgerEntry::create($r);
            return ['expense' => $expense, 'ledger_count' => count($rows)];
        });

        Log::info('finance.expense.captured', [
            'expense_id'  => $result['expense']->id,
            'amount'      => $data['amount'],
            'category'    => $data['category'] ?? null,
            'slack_id'    => $data['slack_message_id'],
            'ledger_rows' => $result['ledger_count'],
        ]);

        return response()->json([
            'id' => $result['expense']->id,
            'ledger_entries' => $result['ledger_count'],
        ], 201);
    }
}
