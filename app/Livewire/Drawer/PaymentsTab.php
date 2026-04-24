<?php

namespace App\Livewire\Drawer;

use App\Models\Payment;
use Livewire\Component;

class PaymentsTab extends Component
{
    public int $studentId;

    public function render()
    {
        $payments = Payment::where('student_id', $this->studentId)->orderByDesc('created_at')->get();
        return view('livewire.drawer.payments-tab', compact('payments'));
    }
}
