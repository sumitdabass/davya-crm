<?php

namespace App\Livewire\Drawer;

use App\Models\Student;
use Livewire\Component;

abstract class DrawerTab extends Component
{
    public int $studentId;

    /**
     * Authorize access to the student being peeked. Every tab sub-component
     * MUST inherit this mount() so Livewire cannot be used to bypass the
     * parent drawer's scopeVisibleTo gate by POSTing directly to the tab.
     */
    public function mount(int $studentId): void
    {
        $this->studentId = $studentId;

        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }

        $visible = Student::query()
            ->visibleTo($user)
            ->whereKey($studentId)
            ->exists();

        if (! $visible) {
            abort(403);
        }
    }
}
