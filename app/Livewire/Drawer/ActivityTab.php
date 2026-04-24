<?php

namespace App\Livewire\Drawer;

use App\Models\Student;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class ActivityTab extends Component
{
    public int $studentId;

    public function render()
    {
        $activities = Activity::where('subject_type', Student::class)
            ->where('subject_id', $this->studentId)
            ->with('causer')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return view('livewire.drawer.activity-tab', compact('activities'));
    }
}
