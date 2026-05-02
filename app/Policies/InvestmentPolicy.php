<?php

namespace App\Policies;

use App\Models\Investment;
use App\Models\User;

class InvestmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function view(User $user, Investment $investment): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function update(User $user, Investment $investment): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function delete(User $user, Investment $investment): bool
    {
        return $user->isSuperAdmin();
    }
}
