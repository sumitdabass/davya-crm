<x-filament-panels::page>
    @php($board = $this->getBoard())

    <div class="fi-kanban-scroll overflow-x-auto pb-4" style="min-height: 70vh;">
        <div class="flex gap-4 items-start" style="min-width: max-content;">
            @foreach ($board as $col)
                <div class="fi-kanban-col bg-gray-50 dark:bg-gray-900/40 rounded-lg p-3 flex flex-col"
                     style="width: 18rem; min-width: 18rem;">
                    <header class="mb-3">
                        <div class="flex items-baseline justify-between">
                            <h3 class="font-semibold text-sm text-gray-900 dark:text-gray-100">
                                {{ $col['stage'] }}
                            </h3>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $col['count'] }} {{ \Illuminate\Support\Str::plural('student', $col['count']) }}
                            </span>
                        </div>
                        <dl class="mt-1 text-xs text-gray-600 dark:text-gray-400 space-y-0.5">
                            <div class="flex justify-between">
                                <dt>Deal</dt>
                                <dd class="tabular-nums">₹{{ number_format($col['deal'], 0, '.', ',') }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Received</dt>
                                <dd class="tabular-nums text-emerald-600 dark:text-emerald-400">
                                    ₹{{ number_format($col['received'], 0, '.', ',') }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Pending</dt>
                                <dd class="tabular-nums text-amber-600 dark:text-amber-400">
                                    ₹{{ number_format($col['pending'], 0, '.', ',') }}
                                </dd>
                            </div>
                        </dl>
                    </header>

                    <div class="flex-1 space-y-2 overflow-y-auto" style="max-height: 60vh;">
                        @forelse ($col['students'] as $s)
                            <a href="{{ \App\Filament\Resources\StudentResource::getUrl('edit', ['record' => $s['id']]) }}"
                               class="block bg-white dark:bg-gray-800 rounded-md p-3 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 hover:ring-primary-500 transition">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">
                                            {{ $s['name'] }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                            {{ $s['phone'] }}
                                        </p>
                                    </div>
                                    @if ($s['owner'])
                                        <span class="shrink-0 text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            {{ $s['owner'] }}
                                        </span>
                                    @endif
                                </div>

                                @if ($s['deal'] > 0)
                                    <div class="mt-2 flex items-center justify-between text-xs">
                                        <span class="tabular-nums text-gray-700 dark:text-gray-300">
                                            ₹{{ number_format($s['deal'], 0, '.', ',') }}
                                        </span>
                                        @if ($s['pending'] > 0)
                                            <span class="tabular-nums text-amber-600 dark:text-amber-400">
                                                ₹{{ number_format($s['pending'], 0, '.', ',') }} pending
                                            </span>
                                        @endif
                                    </div>
                                @endif

                                <div class="mt-2 flex items-center gap-2 text-[10px] text-gray-500 dark:text-gray-400">
                                    @if ($s['current_round'])
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700">
                                            {{ $s['current_round'] }}
                                        </span>
                                    @endif
                                    <span>{{ $s['days_in_stage'] }}d in stage</span>
                                </div>
                            </a>
                        @empty
                            <p class="text-xs text-gray-400 dark:text-gray-600 italic p-2">
                                Empty
                            </p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
