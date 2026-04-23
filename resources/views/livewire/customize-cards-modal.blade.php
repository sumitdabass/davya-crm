<div>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" defer></script>
    @if ($isOpen)
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full max-w-md bg-white dark:bg-gray-900 shadow-xl flex flex-col">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-semibold">Customize {{ ucfirst($surface) }}</h2>
                <button wire:click="close" aria-label="Close" class="text-gray-500 hover:text-red-500">&#x2715;</button>
            </div>

            <p class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
                Drag to reorder. Uncheck to hide.
            </p>

            <div class="flex-1 overflow-auto px-4" id="customize-sortable-{{ $surface }}">
                @foreach ($enabledItems as $item)
                    <div
                        class="flex items-center gap-3 py-2 border-b border-gray-100 dark:border-gray-800 cursor-grab sortable-item"
                        data-id="{{ $item['id'] }}"
                    >
                        <span class="text-gray-400 select-none">&#x2837;</span>
                        <input
                            type="checkbox"
                            checked
                            wire:click="toggle('{{ $item['id'] }}')"
                            class="rounded"
                        />
                        <span>{{ $item['label'] }}</span>
                    </div>
                @endforeach

                @foreach ($disabledItems as $item)
                    <div class="flex items-center gap-3 py-2 border-b border-gray-100 dark:border-gray-800">
                        <span class="text-gray-400 select-none">&#x2837;</span>
                        <input
                            type="checkbox"
                            wire:click="toggle('{{ $item['id'] }}')"
                            class="rounded"
                        />
                        <span>{{ $item['label'] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-between px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                <button wire:click="resetToDefaults" class="text-sm text-gray-600 hover:underline">
                    Reset to defaults
                </button>
                <div class="flex items-center gap-2">
                    <button wire:click="close" class="px-3 py-1.5 text-sm">Cancel</button>
                    <button wire:click="save" class="rounded bg-primary-600 px-3 py-1.5 text-sm text-white">Save</button>
                </div>
            </div>
        </div>
    @endif

    @if ($isOpen)
        @script
        <script>
            (function () {
                const container = document.getElementById('customize-sortable-{{ $surface }}');
                if (!container || container.dataset.sortableReady) return;
                container.dataset.sortableReady = 'true';

                const onReady = () => {
                    if (typeof Sortable === 'undefined') return setTimeout(onReady, 50);
                    Sortable.create(container, {
                        animation: 150,
                        draggable: '.sortable-item',
                        onEnd: function () {
                            const ids = Array.from(container.querySelectorAll('.sortable-item'))
                                .map(el => el.dataset.id);
                            @this.call('reorder', ids);
                        },
                    });
                };
                onReady();
            })();
        </script>
        @endscript
    @endif
</div>
