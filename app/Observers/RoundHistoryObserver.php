<?php

namespace App\Observers;

use App\Models\RoundHistory;
use App\Services\ActivityDescriber;

class RoundHistoryObserver
{
    public function __construct(private readonly ActivityDescriber $describer)
    {
    }

    public function created(RoundHistory $r): void
    {
        $this->describer->roundEntered($r);
    }

    public function updated(RoundHistory $r): void
    {
        if ($r->wasChanged('outcome')) {
            $this->describer->roundOutcomeUpdated($r);
        }
    }
}
