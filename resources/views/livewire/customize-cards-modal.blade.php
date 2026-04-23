<div>
    @if ($isOpen)
        <div
            style="position: fixed; inset: 0; z-index: 9998; background: rgba(0,0,0,0.45);"
            wire:click="close"
        ></div>
        <div
            style="position: fixed; top: 0; right: 0; bottom: 0; z-index: 9999; width: 100%; max-width: 28rem; background: white; box-shadow: -4px 0 12px rgba(0,0,0,0.1); display: flex; flex-direction: column;"
            class="dark:!bg-gray-900"
        >
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #e5e7eb;">
                <h2 style="font-weight: 600; font-size: 16px; margin: 0;">Customize {{ ucfirst($surface) }}</h2>
                <button
                    type="button"
                    wire:click="close"
                    aria-label="Close"
                    style="background: transparent; border: none; font-size: 20px; cursor: pointer; color: #6b7280;"
                >&#x2715;</button>
            </div>

            <p style="padding: 10px 16px 6px; font-size: 12px; color: #6b7280; margin: 0;">
                Use the up/down arrows to reorder. Hide/Show to toggle.
            </p>

            <div style="padding: 4px 16px 4px; font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280;">
                Shown on this page
            </div>
            <div style="flex: 1; overflow-y: auto;">
                @foreach ($enabledItems as $i => $item)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; border-bottom: 1px solid #f3f4f6;">
                        <button
                            type="button"
                            wire:click="moveUp('{{ $item['id'] }}')"
                            title="Move up"
                            style="width: 28px; height: 28px; border: 1px solid #d1d5db; background: #f9fafb; color: #111827; border-radius: 4px; cursor: pointer; font-size: 14px; line-height: 1; {{ $i === 0 ? 'opacity: 0.3; cursor: not-allowed;' : '' }}"
                            {{ $i === 0 ? 'disabled' : '' }}
                        >&#x25B2;</button>
                        <button
                            type="button"
                            wire:click="moveDown('{{ $item['id'] }}')"
                            title="Move down"
                            style="width: 28px; height: 28px; border: 1px solid #d1d5db; background: #f9fafb; color: #111827; border-radius: 4px; cursor: pointer; font-size: 14px; line-height: 1; {{ $i === count($enabledItems) - 1 ? 'opacity: 0.3; cursor: not-allowed;' : '' }}"
                            {{ $i === count($enabledItems) - 1 ? 'disabled' : '' }}
                        >&#x25BC;</button>
                        <span style="flex: 1; font-size: 14px; color: #111827;">{{ $item['label'] }}</span>
                        <button
                            type="button"
                            wire:click="toggle('{{ $item['id'] }}')"
                            style="padding: 4px 10px; font-size: 12px; border: 1px solid #fca5a5; color: #b91c1c; background: white; border-radius: 4px; cursor: pointer;"
                        >Hide</button>
                    </div>
                @endforeach

                @if (count($disabledItems) > 0)
                    <div style="padding: 12px 16px 4px; font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; color: #6b7280;">
                        Available to add
                    </div>
                @endif
                @foreach ($disabledItems as $item)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; border-bottom: 1px solid #f3f4f6; background: #fafafa;">
                        <span style="flex: 1; font-size: 14px; color: #4b5563;">{{ $item['label'] }}</span>
                        <button
                            type="button"
                            wire:click="toggle('{{ $item['id'] }}')"
                            style="padding: 4px 10px; font-size: 12px; border: none; color: white; background: #059669; border-radius: 4px; cursor: pointer;"
                        >Show</button>
                    </div>
                @endforeach
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-top: 1px solid #e5e7eb; background: white;">
                <button
                    type="button"
                    wire:click="resetToDefaults"
                    style="font-size: 13px; color: #6b7280; background: transparent; border: none; cursor: pointer; text-decoration: underline;"
                >Reset to defaults</button>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <button
                        type="button"
                        wire:click="close"
                        style="padding: 6px 12px; font-size: 13px; border: 1px solid #d1d5db; background: white; border-radius: 4px; cursor: pointer;"
                    >Cancel</button>
                    <button
                        type="button"
                        wire:click="save"
                        style="padding: 6px 14px; font-size: 13px; color: white; background: #2563eb; border: none; border-radius: 4px; cursor: pointer; font-weight: 500;"
                    >Save</button>
                </div>
            </div>
        </div>
    @endif
</div>
