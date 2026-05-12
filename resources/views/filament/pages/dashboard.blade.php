<x-filament-panels::page>
    <div style="display: flex; align-items: center; justify-content: flex-end; margin-bottom: 16px;">
        <button
            type="button"
            wire:click="$dispatch('open-customize-modal', { surface: 'dashboard' })"
            class="davya-action davya-action--solid"
        >Customize</button>
    </div>

    @if (config('davyas.visual_v2'))
        @if (count($this->cards()) === 0)
            <div class="davya-section-card">
                <div style="text-align: center; padding: 48px 0; color: #6b7280;">
                    You've hidden all cards.
                    <button
                        type="button"
                        wire:click="$dispatch('reset-cards-to-defaults', { surface: 'dashboard' })"
                        class="davya-action" style="text-decoration:underline;"
                    >Reset to defaults</button>
                    <span style="margin: 0 6px;">·</span>
                    <button
                        type="button"
                        wire:click="$dispatch('open-customize-modal', { surface: 'dashboard' })"
                        class="davya-action" style="text-decoration:underline;"
                    >Customize &rarr;</button>
                </div>
            </div>
        @else
            <div class="davya-section-card">
                <div class="davya-section-card-title">Dashboard</div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach ($this->cards() as $card)
                        <x-dashboard.card-frame
                            :card="$card"
                            :viewer="auth()->user()"
                            :surface="$this->surface()"
                        />
                    @endforeach
                </div>
            </div>
        @endif
    @else
        @if (count($this->cards()) === 0)
            <div style="text-align: center; padding: 48px 0; color: #6b7280;">
                You've hidden all cards.
                <button
                    type="button"
                    wire:click="$dispatch('reset-cards-to-defaults', { surface: 'dashboard' })"
                    class="davya-action" style="text-decoration:underline;"
                >Reset to defaults</button>
                <span style="margin: 0 6px;">·</span>
                <button
                    type="button"
                    wire:click="$dispatch('open-customize-modal', { surface: 'dashboard' })"
                    class="davya-action" style="text-decoration:underline;"
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
                    class="davya-action davya-action--ghost-light"
                    x-on:click="
                        $wire.dispatch('undo-remove', { surface: toast.surface, cardId: toast.cardId, position: toast.position });
                        toast = null;
                    "
                >Undo</button>
            </div>
        </template>
    </div>
</x-filament-panels::page>
