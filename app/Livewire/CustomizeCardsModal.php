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
