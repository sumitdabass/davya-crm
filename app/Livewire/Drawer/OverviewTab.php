<?php

namespace App\Livewire\Drawer;

use App\Models\Student;
use Livewire\Component;

class OverviewTab extends Component
{
    public int $studentId;

    public function render()
    {
        $s = Student::with('owner')->findOrFail($this->studentId);
        $deal = (float) ($s->deal_amount ?? 0);
        $received = (float) ($s->total_received ?? 0);
        $pending = max(0, $deal - $received);
        $pct = $deal > 0 ? min(100, (int) round(($received / $deal) * 100)) : 0;

        return view('livewire.drawer.overview-tab', compact('s', 'deal', 'received', 'pending', 'pct'));
    }
}
