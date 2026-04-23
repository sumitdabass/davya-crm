@props([
    'card',
    'viewer',
    'showHeaderActions' => true,
])

<div
    style="border-radius: 8px; border: 1px solid #e5e7eb; background: white; box-shadow: 0 1px 2px rgba(0,0,0,0.05);"
    wire:key="card-{{ $card->id() }}"
    data-card-id="{{ $card->id() }}"
>
    <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-bottom: 1px solid #e5e7eb;">
        <h3 style="font-size: 13px; font-weight: 600; color: #111827; margin: 0;">
            {{ $card->label() }}
        </h3>
        @if ($showHeaderActions)
            <div style="display: flex; align-items: center; gap: 8px;">
                @if ($href = $card->viewAllHref($viewer))
                    <a href="{{ $href }}"
                       style="font-size: 11px; color: #2563eb; text-decoration: none;"
                       onmouseover="this.style.textDecoration='underline';"
                       onmouseout="this.style.textDecoration='none';">View all &rarr;</a>
                @endif
                <button
                    type="button"
                    wire:click="$dispatch('remove-card', { surface: '{{ $surface ?? 'dashboard' }}', cardId: '{{ $card->id() }}' })"
                    title="Remove card"
                    aria-label="Remove {{ $card->label() }}"
                    style="background: transparent; border: none; cursor: pointer; color: #9ca3af; font-size: 14px; padding: 2px 6px; line-height: 1;"
                    onmouseover="this.style.color='#ef4444';"
                    onmouseout="this.style.color='#9ca3af';"
                >&#x2715;</button>
            </div>
        @endif
    </div>

    <div>
        {!! $card->render($viewer) !!}
    </div>
</div>
