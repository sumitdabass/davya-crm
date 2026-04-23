<x-filament-panels::page>
    <div class="flex items-center justify-between mb-4">
        <div></div>
        <button
            type="button"
            wire:click="$dispatch('open-customize-modal', { surface: 'dashboard' })"
            class="inline-flex items-center gap-2 rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500"
        >
            Customize
        </button>
    </div>

    @if (count($this->cards()) === 0)
        <div class="text-center py-12 text-gray-500">
            No cards enabled.
            <button wire:click="$dispatch('open-customize-modal', { surface: 'dashboard' })"
                    class="text-primary-600 hover:underline">
                Customize &rarr;
            </button>
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
</x-filament-panels::page>
