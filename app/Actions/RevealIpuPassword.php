<?php

namespace App\Actions;

use App\Models\Student;
use Illuminate\Support\Facades\Gate;

class RevealIpuPassword
{
    public function __invoke(Student $student): string
    {
        Gate::authorize('view', $student);

        activity()
            ->performedOn($student)
            ->causedBy(auth()->user())
            ->event('ipu_password_revealed')
            ->log('ipu_password_revealed');

        return $student->ipu_password;
    }
}
