<?php

namespace App\Observers;

use App\Models\StudentNote;
use App\Services\ActivityDescriber;

class StudentNoteObserver
{
    public function __construct(private readonly ActivityDescriber $describer)
    {
    }

    public function created(StudentNote $n): void
    {
        $this->describer->noteAdded($n);
    }
}
