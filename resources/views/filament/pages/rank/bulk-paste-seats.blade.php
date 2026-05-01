<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}

        <div class="mt-6 flex gap-3">
            <x-filament::button type="submit">
                Import
            </x-filament::button>
            <x-filament::button type="button" color="gray" tag="a" href="{{ \App\Filament\Resources\Rank\SeatResource::getUrl('index') }}">
                Cancel
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
