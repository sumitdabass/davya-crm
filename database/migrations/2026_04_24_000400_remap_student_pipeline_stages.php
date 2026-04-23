<?php

use App\Support\RoundNameToStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $counts = [
            'Onboarded' => 0,
            'University Registration' => 0,
            'Counselling In Progress' => 0,
            'Full Payment Received' => 0,
            'Admission Confirmed' => 0,
        ];

        // Onboarded → Advance Received (simple batch update).
        $counts['Onboarded'] = DB::table('students')
            ->where('stage', 'Onboarded')
            ->update(['stage' => 'Advance Received']);

        // University Registration → derive from latest round_history, else Advance Received.
        $uniRegIds = DB::table('students')->where('stage', 'University Registration')->pluck('id');
        foreach ($uniRegIds as $id) {
            $newStage = $this->deriveRoundStage((int) $id) ?? 'Advance Received';
            DB::table('students')->where('id', $id)->update(['stage' => $newStage]);
            $counts['University Registration']++;
        }

        // Counselling In Progress → derive from latest round_history, else MQ.
        $cipIds = DB::table('students')->where('stage', 'Counselling In Progress')->pluck('id');
        foreach ($cipIds as $id) {
            $newStage = $this->deriveRoundStage((int) $id) ?? 'MQ';
            DB::table('students')->where('id', $id)->update(['stage' => $newStage]);
            $counts['Counselling In Progress']++;
        }

        // Full Payment Received → Seat Allotted.
        $counts['Full Payment Received'] = DB::table('students')
            ->where('stage', 'Full Payment Received')
            ->update(['stage' => 'Seat Allotted']);

        // Admission Confirmed → Closed + close_reason='Completed' (only if blank, preserve existing).
        $admIds = DB::table('students')->where('stage', 'Admission Confirmed')->pluck('id');
        foreach ($admIds as $id) {
            $current = DB::table('students')->where('id', $id)->value('close_reason');
            DB::table('students')->where('id', $id)->update([
                'stage' => 'Closed',
                'close_reason' => $current ?: 'Completed',
            ]);
            $counts['Admission Confirmed']++;
        }

        Log::info('Student stage remap complete', $counts);
    }

    public function down(): void
    {
        // Not reversible: original stage values are not preserved.
    }

    private function deriveRoundStage(int $studentId): ?string
    {
        $latest = DB::table('round_history')
            ->where('student_id', $studentId)
            ->orderByDesc('id')
            ->value('round_name');
        if ($latest === null) {
            return null;
        }
        return RoundNameToStage::stageName($latest);
    }
};
