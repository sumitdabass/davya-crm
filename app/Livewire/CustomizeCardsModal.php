<?php

namespace App\Livewire;

use App\Dashboard\Card;
use App\Dashboard\CardRegistry;
use App\Dashboard\Resolver\UserPrefsResolver;
use Livewire\Attributes\On;
use Livewire\Component;

class CustomizeCardsModal extends Component
{
    public bool $isOpen = false;
    public string $surface = 'dashboard';
    /** @var string[] Ordered array of enabled card ids. */
    public array $enabled = [];

    #[On('open-customize-modal')]
    public function open(string $surface): void
    {
        $this->surface = $surface;
        $resolver = app(UserPrefsResolver::class);
        $this->enabled = array_map(
            fn (Card $c) => $c->id(),
            $resolver->resolve(auth()->user(), $surface),
        );
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function toggle(string $cardId): void
    {
        if (in_array($cardId, $this->enabled, true)) {
            $this->enabled = array_values(array_filter($this->enabled, fn ($id) => $id !== $cardId));
        } else {
            $this->enabled[] = $cardId;
        }
    }

    /** @param string[] $newOrder */
    public function reorder(array $newOrder): void
    {
        $enabledSet = array_flip($this->enabled);
        $this->enabled = array_values(array_filter(
            $newOrder,
            fn ($id) => isset($enabledSet[$id]),
        ));
    }

    public function moveUp(string $cardId): void
    {
        $i = array_search($cardId, $this->enabled, true);
        if ($i === false || $i === 0) {
            return;
        }
        [$this->enabled[$i - 1], $this->enabled[$i]] = [$this->enabled[$i], $this->enabled[$i - 1]];
    }

    public function moveDown(string $cardId): void
    {
        $i = array_search($cardId, $this->enabled, true);
        if ($i === false || $i === count($this->enabled) - 1) {
            return;
        }
        [$this->enabled[$i + 1], $this->enabled[$i]] = [$this->enabled[$i], $this->enabled[$i + 1]];
    }

    public function save(): void
    {
        $user = auth()->user();
        $prefs = $user->dashboard_prefs ?? [];
        $prefs[$this->surface] = ['enabled' => $this->enabled];
        $user->dashboard_prefs = $prefs;
        $user->save();

        $this->dispatch('dashboard-prefs-saved', surface: $this->surface);
        $this->close();
    }

    public function resetToDefaults(): void
    {
        $user = auth()->user();
        $prefs = $user->dashboard_prefs ?? [];
        unset($prefs[$this->surface]);
        $user->dashboard_prefs = $prefs === [] ? null : $prefs;
        $user->save();

        $resolver = app(UserPrefsResolver::class);
        $this->enabled = array_map(
            fn (Card $c) => $c->id(),
            $resolver->resolve($user, $this->surface),
        );
    }

    #[On('reset-cards-to-defaults')]
    public function onResetFromEmptyState(string $surface): void
    {
        $this->surface = $surface;
        $this->resetToDefaults();
        $this->dispatch('dashboard-prefs-saved', surface: $surface);
    }

    #[On('remove-card')]
    public function removeCardFromSurface(string $surface, string $cardId): void
    {
        $user = auth()->user();
        $prefs = $user->dashboard_prefs ?? [];
        $enabled = $prefs[$surface]['enabled'] ?? null;

        if ($enabled === null) {
            // Materialise defaults so we can remove from them.
            $resolver = app(UserPrefsResolver::class);
            $enabled = array_map(fn (Card $c) => $c->id(), $resolver->resolve($user, $surface));
        }

        $position = array_search($cardId, $enabled, true);
        if ($position === false) {
            return;
        }
        $enabled = array_values(array_filter($enabled, fn ($id) => $id !== $cardId));
        $prefs[$surface] = ['enabled' => $enabled];
        $user->dashboard_prefs = $prefs;
        $user->save();

        $this->dispatch('card-removed', cardId: $cardId, surface: $surface, position: $position);
    }

    public function undoRemove(string $surface, string $cardId, int $position): void
    {
        $user = auth()->user();
        $prefs = $user->dashboard_prefs ?? [];
        $enabled = $prefs[$surface]['enabled'] ?? [];

        if (in_array($cardId, $enabled, true)) {
            return;
        }
        array_splice($enabled, min($position, count($enabled)), 0, [$cardId]);
        $prefs[$surface] = ['enabled' => $enabled];
        $user->dashboard_prefs = $prefs;
        $user->save();
    }

    #[On('undo-remove')]
    public function onUndoRemove(string $surface, string $cardId, int $position): void
    {
        $this->undoRemove($surface, $cardId, $position);
    }

    public function render()
    {
        $enabledSet = array_flip($this->enabled);
        $items = [];
        foreach (CardRegistry::all() as $card) {
            $items[] = [
                'id' => $card->id(),
                'label' => $card->label(),
                'enabled' => isset($enabledSet[$card->id()]),
            ];
        }

        // Enabled items in saved order first, then available-but-disabled below.
        $orderedEnabled = [];
        foreach ($this->enabled as $id) {
            foreach ($items as $item) {
                if ($item['id'] === $id) { $orderedEnabled[] = $item; break; }
            }
        }
        $disabled = array_values(array_filter($items, fn ($i) => !$i['enabled']));

        return view('livewire.customize-cards-modal', [
            'enabledItems' => $orderedEnabled,
            'disabledItems' => $disabled,
        ]);
    }
}
