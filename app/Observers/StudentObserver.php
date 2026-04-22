<?php

namespace App\Observers;

use App\Models\Student;
use App\Models\User;
use App\Services\ActivityDescriber;

class StudentObserver
{
    public function __construct(private readonly ActivityDescriber $describer)
    {
    }

    public function updated(Student $student): void
    {
        if ($student->wasChanged('stage')) {
            $from = $student->getOriginal('stage');
            $to = $student->stage;
            $this->describer->stageChanged($student, $from, $to);

            if ($to === 'Closed') {
                $this->describer->closed($student, $student->close_reason ?? '—');
            } elseif ($from === 'Closed') {
                $this->describer->reopened($student, $student->re_entry_reason ?? '—');
            }
        }

        if ($student->wasChanged('owner_id')) {
            $fromId = $student->getOriginal('owner_id');
            $from = $fromId ? User::find($fromId) : null;
            $to = User::find($student->owner_id);
            if ($to) {
                $this->describer->ownerChanged($student, $from, $to);
            }
        }

        if ($student->wasChanged('ipu_login_code')) {
            $wasSet = (bool) $student->getOriginal('ipu_login_code');
            $this->describer->ipuCodeChanged($student, $wasSet);
        }
    }
}
