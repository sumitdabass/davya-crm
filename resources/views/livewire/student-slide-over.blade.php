<div>
    @if ($isOpen && $payload)
        <div
            class="fixed inset-0 z-40 bg-black/40"
            wire:click="close"
        ></div>
        <div
            class="fixed inset-y-0 right-0 z-50 w-full max-w-xl bg-white dark:bg-gray-900 shadow-xl flex flex-col"
        >
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-semibold">{{ $payload->title }} — {{ $rows->total() }} students</h2>
                <button wire:click="close" aria-label="Close" class="text-gray-500 hover:text-red-500">✕</button>
            </div>

            <div class="p-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search name or phone"
                    class="flex-1 rounded border-gray-300 dark:bg-gray-800"
                />
                {{-- CSV link added in T13 --}}
            </div>

            <div class="flex-1 overflow-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            @foreach ($payload->columns as $col)
                                <th class="px-3 py-2 text-left">{{ $col['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800">
                                @foreach ($payload->columns as $col)
                                    <td class="px-3 py-2">
                                        {{ \App\Dashboard\RowFormatter::format($row, $col['key']) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                {{ $rows->links() }}
                @if ($viewAllHref)
                    <a href="{{ $viewAllHref }}" class="text-sm text-primary-600 hover:underline">
                        Open in full table →
                    </a>
                @endif
            </div>
        </div>
    @endif
</div>
