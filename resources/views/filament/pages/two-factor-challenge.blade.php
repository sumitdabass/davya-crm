<x-filament-panels::page>
    <form wire:submit="verify">
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            Enter the 6-digit code from your authenticator app.
            If you've lost your device, enter a recovery code instead.
        </p>
        {{ $this->form }}
        <div class="mt-4">
            <x-filament::button type="submit">Verify</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
