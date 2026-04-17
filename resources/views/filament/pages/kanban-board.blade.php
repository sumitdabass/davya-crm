<x-filament-panels::page>
    @php($board = $this->getBoard())

    <div class="fi-kanban-scroll overflow-x-auto pb-4" style="min-height: 70vh;"
         x-data
         x-init="
            (function init(){
                if (!window.Sortable) {
                    const s = document.createElement('script');
                    s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
                    s.onload = () => wireKanban($el, $wire);
                    document.head.appendChild(s);
                } else {
                    wireKanban($el, $wire);
                }
            })();
         ">
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

                    <div class="fi-kanban-col-items flex-1 space-y-2 overflow-y-auto"
                         data-stage="{{ $col['stage'] }}"
                         style="max-height: 60vh; min-height: 3rem;">
                        @foreach ($col['students'] as $s)
                            <div data-student-id="{{ $s['id'] }}"
                                 data-edit-url="{{ \App\Filament\Resources\StudentResource::getUrl('edit', ['record' => $s['id']]) }}"
                                 class="fi-kanban-card block bg-white dark:bg-gray-800 rounded-md p-3 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 hover:ring-primary-500 cursor-grab active:cursor-grabbing transition">
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
                            </div>
                        @endforeach

                        @if ($col['count'] === 0)
                            <p class="text-xs text-gray-400 dark:text-gray-600 italic p-2">Empty — drop here</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <script>
        function wireKanban(root, wire) {
            root.querySelectorAll('.fi-kanban-col-items').forEach((el) => {
                if (el._sortable) return;
                el._sortable = new Sortable(el, {
                    group: 'kanban',
                    animation: 150,
                    ghostClass: 'fi-kanban-ghost',
                    onEnd: async (evt) => {
                        const id = parseInt(evt.item.dataset.studentId, 10);
                        const to = evt.to.dataset.stage;
                        const from = evt.from.dataset.stage;
                        if (!id || to === from) return;
                        const res = await wire.call('moveStudentToStage', id, to);
                        if (!res || !res.ok) {
                            // Move failed — put card back
                            evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex] ?? null);
                        }
                    },
                });
            });

            // Clicking a card opens the edit page. Avoid interfering with drag start.
            root.addEventListener('click', (e) => {
                const card = e.target.closest('.fi-kanban-card');
                if (!card) return;
                const url = card.dataset.editUrl;
                if (url) window.location.href = url;
            });

            // Re-wire after Livewire re-renders the component
            if (window.Livewire) {
                Livewire.hook('morph.updated', ({ el }) => {
                    if (root.contains(el)) {
                        root.querySelectorAll('.fi-kanban-col-items').forEach((el) => {
                            if (el._sortable) return;
                            el._sortable = new Sortable(el, {
                                group: 'kanban',
                                animation: 150,
                                ghostClass: 'fi-kanban-ghost',
                            });
                        });
                    }
                });
            }
        }
    </script>

    <style>
        .fi-kanban-ghost { opacity: .4; }
    </style>
</x-filament-panels::page>
