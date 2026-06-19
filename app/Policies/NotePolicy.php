<?php

namespace App\Policies;

use App\Models\Note;
use App\Models\User;

class NotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function view(User $user, Note $note): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function update(User $user, Note $note): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function delete(User $user, Note $note): bool
    {
        return $user->isSuperAdmin();
    }
}
