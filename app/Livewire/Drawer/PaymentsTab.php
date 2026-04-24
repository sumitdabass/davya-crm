<?php

namespace App\Livewire\Drawer;

use App\Models\Payment;

class PaymentsTab extends DrawerTab
{
    public function render()
    {
        $payments = Payment::where('student_id', $this->studentId)->orderByDesc('created_at')->get();
        return view('livewire.drawer.payments-tab', compact('payments'));
    }
}
