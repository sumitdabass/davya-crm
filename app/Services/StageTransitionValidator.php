<?php

namespace App\Services;

use App\Enums\PipelineStage;
use App\Models\Student;

class StageTransitionValidator
{
    /** @return string[] soft warnings */
    public function forRoundChange(Student $student, string $newRound): array
    {
        $warnings = [];

        $latest = $student->roundHistory()->latest()->first();
        if ($latest && str_starts_with($latest->outcome, 'Allotted — Fee Pending')) {
            $warnings[] = "Seat fee unpaid for {$latest->round_name}. Continue anyway?";
        }

        if ($newRound === 'Online_Sliding') {
            $hasPrior = $student->roundHistory()
                ->where('outcome', 'like', 'Allotted%')
                ->exists();
            if (! $hasPrior) {
                $warnings[] = 'Not eligible for Sliding (no prior allotment).';
            }
        }

        return $warnings;
    }

    /**
     * @return array{hard: string[], soft: string[]}
     */
    public function forStageChange(Student $student, string $newStage): array
    {
        $hard = [];
        $soft = [];

        // Hard: Closed requires close_reason.
        if ($newStage === 'Closed' && empty($student->close_reason)) {
            $hard[] = 'close_reason is required when moving to Closed.';
        }

        // Hard: re-opening requires re_entry_reason.
        if ($student->getOriginal('stage') === 'Closed'
            && $newStage !== 'Closed'
            && empty($student->re_entry_reason)
        ) {
            $hard[] = 're_entry_reason is required when re-opening a closed student.';
        }

        // Soft gates by target.
        switch ($newStage) {
            case 'Meeting Scheduled':
                $hasFuture = $student->meetings()
                    ->where('status', 'scheduled')
                    ->where('scheduled_at', '>=', now())
                    ->exists();
                if (! $hasFuture) {
                    $soft[] = 'Meeting Scheduled incomplete: schedule a meeting (date + title) in the Meetings tab.';
                }
                break;

            case 'Meeting Done':
                if (empty($student->student_response)) {
                    $soft[] = 'Meeting Done incomplete: set student_response (Ready / Not Interested / Needs Time).';
                }
                break;

            case 'Advance Received':
                if (! $student->payments()->exists()) {
                    $soft[] = 'Advance Received incomplete: record the advance payment on the Deal tab.';
                }
                break;

            case 'Round 1':
            case 'Round 2':
            case 'Round 3':
            case 'Sliding':
            case 'Offline':
                $targetStage = PipelineStage::from($newStage);
                $matchingRoundNames = array_keys(array_filter(
                    [
                        'Online_R1' => PipelineStage::Round1, 'S2_R1' => PipelineStage::Round1,
                        'Online_R2' => PipelineStage::Round2,
                        'Online_R3' => PipelineStage::Round3, 'S2_R3' => PipelineStage::Round3,
                        'Online_Sliding' => PipelineStage::Sliding, 'Online_Reporting' => PipelineStage::Sliding,
                        'Offline_R1' => PipelineStage::Offline, 'Offline_R2' => PipelineStage::Offline,
                    ],
                    fn (PipelineStage $s) => $s === $targetStage,
                ));
                if (! $student->roundHistory()->whereIn('round_name', $matchingRoundNames)->exists()) {
                    $soft[] = "$newStage incomplete: create a round_history row with round_name matching $newStage.";
                }
                break;

            case 'Seat Allotted':
                $latest = $student->roundHistory()->latest()->first();
                if (! $latest || empty($latest->allotted_college)) {
                    $soft[] = 'Seat Allotted incomplete: set allotted_college on the latest round row.';
                }
                break;
        }

        return ['hard' => $hard, 'soft' => $soft];
    }
}
