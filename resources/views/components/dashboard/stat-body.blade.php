@props([
    'cardId',
    'label',
    'value',
    'secondary' => null,
    'drillable' => false,
])

<div style="padding: 16px;">
    <div style="display: flex; align-items: baseline; gap: 12px;">
        @if ($drillable)
            <button
                type="button"
                wire:click="$dispatch('open-slide-over', { cardId: '{{ $cardId }}' })"
                style="font-size: 30px; font-weight: 600; color: #059669; background: transparent; border: none; cursor: pointer; padding: 0; line-height: 1;"
                onmouseover="this.style.textDecoration='underline';"
                onmouseout="this.style.textDecoration='none';"
            >{{ $value }}</button>
        @else
            <span style="font-size: 30px; font-weight: 600; color: #111827;">{{ $value }}</span>
        @endif
    </div>
    @if ($secondary)
        <div style="margin-top: 4px; font-size: 13px; color: #6b7280;">{{ $secondary }}</div>
    @endif
</div>
