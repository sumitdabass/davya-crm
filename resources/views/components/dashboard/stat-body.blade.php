@props([
    'cardId',
    'label',
    'value',
    'secondary' => null,
    'drillable' => false,
])

<div class="p-4">
    <div class="flex items-baseline gap-3">
        <button
            @if ($drillable)
                wire:click="$dispatch('open-slide-over', { cardId: '{{ $cardId }}' })"
                class="text-3xl font-semibold text-primary-600 hover:underline"
            @else
                class="text-3xl font-semibold text-gray-900 dark:text-gray-100"
                disabled
            @endif
        >
            {{ $value }}
        </button>
    </div>
    @if ($secondary)
        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $secondary }}</div>
    @endif
</div>
