<x-filament-panels::page>
    <div style="display: flex; align-items: center; justify-content: flex-end; margin-bottom: 16px;">
        <button
            type="button"
            wire:click="$dispatch('open-customize-modal', { surface: 'dashboard' })"
            style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; font-size: 13px; font-weight: 500; color: white; background: #059669; border: none; border-radius: 4px; cursor: pointer;"
            onmouseover="this.style.background='#047857';"
            onmouseout="this.style.background='#059669';"
        >Customize</button>
    </div>

    @if (count($this->cards()) === 0)
        <div style="text-align: center; padding: 48px 0; color: #6b7280;">
            No cards enabled.
            <button
                type="button"
                wire:click="$dispatch('open-customize-modal', { surface: 'dashboard' })"
                style="color: #059669; background: transparent; border: none; cursor: pointer; text-decoration: underline; padding: 0; font-size: inherit;"
            >Customize &rarr;</button>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($this->cards() as $card)
                <x-dashboard.card-frame
                    :card="$card"
                    :viewer="auth()->user()"
                    :surface="$this->surface()"
                />
            @endforeach
        </div>
    @endif

    @livewire(\App\Livewire\StudentSlideOver::class)
    @livewire(\App\Livewire\CustomizeCardsModal::class)

    <div
        x-data="{ toast: null }"
        x-on:card-removed.window="
            toast = $event.detail;
            setTimeout(() => { if (toast === $event.detail) toast = null }, 8000);
        "
        style="position: fixed; bottom: 16px; right: 16px; z-index: 9997;"
    >
        <template x-if="toast">
            <div style="display: flex; align-items: center; gap: 12px; padding: 10px 16px; background: #111827; color: white; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.25); font-size: 13px;">
                <span>Removed <span x-text="toast.cardId"></span>.</span>
                <button
                    type="button"
                    style="background: transparent; border: none; color: white; text-decoration: underline; cursor: pointer; font-size: 13px;"
                    x-on:click="
                        $wire.dispatch('undo-remove', { surface: toast.surface, cardId: toast.cardId, position: toast.position });
                        toast = null;
                    "
                >Undo</button>
            </div>
        </template>
    </div>
</x-filament-panels::page>
