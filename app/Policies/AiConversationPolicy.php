<?php
namespace App\Policies;

use App\Models\AiConversation;
use App\Models\User;

class AiConversationPolicy
{
    public function viewAny(User $user): bool { return $user->can('use ai-agent'); }

    public function view(User $user, AiConversation $conversation): bool
    {
        if ($conversation->user_id === $user->id) return true;
        return $user->hasAnyRole(['admin', 'super_admin']);
    }
}
