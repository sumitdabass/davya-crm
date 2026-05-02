<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;

class MeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Meeting $meeting): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }
        return Meeting::query()->where('id', $meeting->id)->visibleTo($user)->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Meeting $meeting): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }
        if ($user->hasRole('head')) {
            return $this->view($user, $meeting);
        }
        return (int) $meeting->owner_id === (int) $user->id;
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $user->isSuperAdmin();
    }
}
