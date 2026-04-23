<?php

namespace App\Dashboard\Resolver;

use App\Dashboard\Card;
use App\Dashboard\CardRegistry;
use App\Models\User;

class UserPrefsResolver
{
    /** @return Card[] */
    public function resolve(User $user, string $surface): array
    {
        $prefs = $user->dashboard_prefs ?? [];
        $saved = $prefs[$surface]['enabled'] ?? null;

        $available = CardRegistry::all();
        $available = array_filter($available, fn (Card $c) => $c->isAvailableFor($user));
        $availableById = [];
        foreach ($available as $card) {
            $availableById[$card->id()] = $card;
        }

        if ($saved === null) {
            return array_values(array_filter(
                $available,
                fn (Card $c) => $c->isDefaultOn($surface),
            ));
        }

        $resolved = [];
        foreach ($saved as $id) {
            if (isset($availableById[$id])) {
                $resolved[] = $availableById[$id];
            }
        }

        $seenIds = array_map(fn (Card $c) => $c->id(), $resolved);
        foreach ($available as $card) {
            if ($card->isDefaultOn($surface) && !in_array($card->id(), $seenIds, true)) {
                $resolved[] = $card;
            }
        }

        return $resolved;
    }
}
