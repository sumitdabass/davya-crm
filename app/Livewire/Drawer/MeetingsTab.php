<?php

namespace App\Livewire\Drawer;

use App\Models\Meeting;

class MeetingsTab extends DrawerTab
{
    public function render()
    {
        $meetings = Meeting::where('student_id', $this->studentId)
            ->orderByDesc('scheduled_at')
            ->get();
        return view('livewire.drawer.meetings-tab', compact('meetings'));
    }
}
