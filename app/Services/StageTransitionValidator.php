<?php

namespace App\Services;

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

    /** @return string[] hard errors */
    public function forStageChange(Student $student, string $newStage): array
    {
        $errors = [];

        if ($newStage === 'Closed' && empty($student->close_reason)) {
            $errors[] = 'close_reason is required when moving to Closed.';
        }

        if ($student->getOriginal('stage') === 'Closed'
            && $newStage !== 'Closed'
            && empty($student->re_entry_reason)
        ) {
            $errors[] = 're_entry_reason is required when re-opening a closed student.';
        }

        return $errors;
    }
}
