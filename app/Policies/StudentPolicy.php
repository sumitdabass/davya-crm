<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Student $student): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }
        if ($student->owner_id === $user->id || $student->referrer_id === $user->id) {
            return true;
        }
        if ($user->hasRole('head')) {
            $teamIds = User::where('team_head_id', $user->id)->pluck('id')->toArray();
            $teamIds[] = $user->id;
            if (in_array($student->owner_id, $teamIds, true)
                || in_array($student->referrer_id, $teamIds, true)) {
                return true;
            }
            // Admin-owned leads whose lead_source matches a team member label
            // belong to that team's head.
            $adminId = User::role('admin')->value('id');
            if ($student->owner_id === $adminId
                && $student->referrer_id === $adminId
                && $student->lead_source !== null
            ) {
                $teamNames = User::whereIn('id', $teamIds)->pluck('name')->toArray();
                $teamLeadSources = array_merge(
                    $teamNames,
                    array_map(fn ($n) => 'Sheet:'.$n, $teamNames),
                );
                if (in_array($student->lead_source, $teamLeadSources, true)) {
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Student $student): bool
    {
        return $this->view($user, $student);
    }

    public function delete(User $user, Student $student): bool
    {
        return $user->hasRole('admin') || ($user->hasRole('head') && $this->view($user, $student));
    }

    public function transfer(User $user, Student $student): bool
    {
        return $user->hasRole('admin') || ($user->hasRole('head') && $this->view($user, $student));
    }
}
