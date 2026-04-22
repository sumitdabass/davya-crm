<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\ActivityDescriber;

class PaymentObserver
{
    public function __construct(private readonly ActivityDescriber $describer)
    {
    }

    public function created(Payment $p): void
    {
        $this->describer->paymentAdded($p);
    }

    public function updated(Payment $p): void
    {
        $this->describer->paymentUpdated($p);
    }

    public function deleted(Payment $p): void
    {
        // Student accessor may still resolve via FK even after payment delete.
        // If SoftDeletes were used on Student, we'd need ->withTrashed(); it's not.
        $student = $p->student()->first();
        if ($student) {
            $this->describer->paymentDeleted($p, $student);
        }
    }
}
