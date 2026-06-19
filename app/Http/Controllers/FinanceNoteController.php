<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNoteRequest;
use App\Models\Note;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinanceNoteController extends Controller
{
    public function store(StoreNoteRequest $request): JsonResponse
    {
        $data = $request->validated();

        $existing = Note::where('slack_message_id', $data['slack_message_id'])->first();
        if ($existing !== null) {
            return response()->json([
                'error' => 'duplicate_slack_message',
                'existing_id' => $existing->id,
            ], 409);
        }

        try {
            $note = DB::transaction(function () use ($data) {
                return Note::create([
                    'body'             => $data['body'],
                    'slack_message_id' => $data['slack_message_id'],
                    'raw_input'        => $data['raw_input'] ?? null,
                    'noted_at'         => $data['noted_at']  ?? now(),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                $existing = Note::where('slack_message_id', $data['slack_message_id'])->first();
                if ($existing !== null) {
                    return response()->json([
                        'error'       => 'duplicate_slack_message',
                        'existing_id' => $existing->id,
                    ], 409);
                }
            }
            throw $e;
        }

        Log::info('finance.note.captured', [
            'note_id'  => $note->id,
            'slack_id' => $data['slack_message_id'],
        ]);

        return response()->json(['id' => $note->id], 201);
    }
}
