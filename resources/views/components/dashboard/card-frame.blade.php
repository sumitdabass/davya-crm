@props([
    'card',           // App\Dashboard\Card instance
    'viewer',         // App\Models\User
    'showHeaderActions' => true,
])

<div
    class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm"
    wire:key="card-{{ $card->id() }}"
    data-card-id="{{ $card->id() }}"
>
    <div class="flex items-center justify-between px-4 py-2 border-b border-gray-200 dark:border-gray-700">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
            {{ $card->label() }}
        </h3>
        @if ($showHeaderActions)
            <div class="flex items-center gap-2">
                @if ($href = $card->viewAllHref($viewer))
                    <a href="{{ $href }}"
                       class="text-xs text-primary-600 hover:underline">View all →</a>
                @endif
                <button
                    type="button"
                    wire:click="$dispatch('remove-card', { surface: '{{ $surface ?? 'dashboard' }}', cardId: '{{ $card->id() }}' })"
                    class="text-gray-400 hover:text-red-500"
                    title="Remove card"
                    aria-label="Remove {{ $card->label() }}"
                >
                    ✕
                </button>
            </div>
        @endif
    </div>

    <div class="card-body">
        {!! $card->render($viewer) !!}
    </div>
</div>
