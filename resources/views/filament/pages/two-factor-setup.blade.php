<x-filament-panels::page>
    @php($user = auth()->user())

    @if ($recoveryCodes)
        <div class="rounded-lg border border-amber-400 bg-amber-50 dark:bg-amber-900/30 p-4 mb-6">
            <h3 class="font-semibold text-amber-900 dark:text-amber-200 mb-2">
                Save these recovery codes — you won't see them again
            </h3>
            <p class="text-sm text-amber-800 dark:text-amber-300 mb-3">
                Each code works once. If you lose your authenticator device, use one of these to get back in.
            </p>
            <div class="grid grid-cols-2 gap-2 font-mono text-sm bg-white dark:bg-gray-900 p-3 rounded">
                @foreach ($recoveryCodes as $c)
                    <span>{{ $c }}</span>
                @endforeach
            </div>
        </div>
    @endif

    @if ($user->hasTwoFactorEnabled() && ! $recoveryCodes)
        <div class="rounded-lg border border-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 p-4">
            <h3 class="font-semibold text-emerald-900 dark:text-emerald-200 mb-1">Two-factor auth is active</h3>
            <p class="text-sm text-emerald-800 dark:text-emerald-300">
                You'll be asked for a 6-digit code each time you log in.
                Use "Disable 2FA" above if you need to turn it off.
            </p>
        </div>
    @elseif ($pendingSecret)
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h3 class="font-semibold mb-3">1 &middot; Scan in your authenticator app</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                Use Google Authenticator, Authy, 1Password, Bitwarden, etc.
            </p>
            <div class="flex flex-col md:flex-row items-start gap-4">
                <div class="bg-white p-3 rounded inline-block">{!! $this->qrSvg() !!}</div>
                <div class="text-xs text-gray-600 dark:text-gray-400 break-all">
                    Secret (manual entry): <code class="font-mono">{{ $pendingSecret }}</code>
                </div>
            </div>

            <h3 class="font-semibold mt-6 mb-2">2 &middot; Enter the 6-digit code to confirm</h3>
            <form wire:submit="confirmEnroll">
                {{ $this->form }}
                <div class="mt-3 flex gap-2">
                    <x-filament::button type="submit">Confirm &amp; enable</x-filament::button>
                    <x-filament::button color="gray" wire:click="cancelEnroll" type="button">Cancel</x-filament::button>
                </div>
            </form>
        </div>
    @else
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h3 class="font-semibold mb-2">Two-factor auth is not enabled</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                Adds a second step when logging in — a 6-digit code from your authenticator app.
                Strongly recommended.
            </p>
            <x-filament::button wire:click="startEnroll">Start setup</x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
