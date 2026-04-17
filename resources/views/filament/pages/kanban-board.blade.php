<x-filament-panels::page>
    @php($board = $this->getBoard())
    @php($stageAccent = [
        'Lead Captured'           => 'bg-slate-400',
        'Meeting Scheduled'       => 'bg-sky-400',
        'Meeting Done'            => 'bg-cyan-400',
        'Onboarded'               => 'bg-teal-500',
        'University Registration' => 'bg-indigo-500',
        'Counselling In Progress' => 'bg-violet-500',
        'Seat Allotted'           => 'bg-fuchsia-500',
        'Full Payment Received'   => 'bg-emerald-500',
        'Admission Confirmed'     => 'bg-green-600',
        'Closed'                  => 'bg-gray-500',
    ])

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
                <div class="fi-kanban-col bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm ring-1 ring-gray-200/50 dark:ring-gray-700/50 flex flex-col overflow-hidden"
                     style="width: 19rem; min-width: 19rem;">
                    {{-- Stage accent strip --}}
                    <div class="h-1 {{ $stageAccent[$col['stage']] ?? 'bg-gray-300' }}"></div>

                    <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/40">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="font-semibold text-sm text-gray-900 dark:text-gray-100 truncate">
                                {{ $col['stage'] }}
                            </h3>
                            <span class="shrink-0 inline-flex items-center justify-center min-w-[1.5rem] h-5 px-1.5 rounded-full bg-gray-200 dark:bg-gray-700 text-[11px] font-medium text-gray-700 dark:text-gray-200">
                                {{ $col['count'] }}
                            </span>
                        </div>
                        <dl class="mt-2 text-[11px] text-gray-600 dark:text-gray-400 space-y-0.5">
                            <div class="flex justify-between">
                                <dt>Deal</dt>
                                <dd class="tabular-nums font-medium text-gray-900 dark:text-gray-100">
                                    ₹{{ number_format($col['deal'], 0, '.', ',') }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Received</dt>
                                <dd class="tabular-nums text-emerald-600 dark:text-emerald-400 font-medium">
                                    ₹{{ number_format($col['received'], 0, '.', ',') }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt>Pending</dt>
                                <dd class="tabular-nums text-amber-600 dark:text-amber-400 font-medium">
                                    ₹{{ number_format($col['pending'], 0, '.', ',') }}
                                </dd>
                            </div>
                        </dl>
                    </header>

                    <div class="fi-kanban-col-items flex-1 space-y-2 overflow-y-auto p-3 bg-gray-50 dark:bg-gray-900/60"
                         data-stage="{{ $col['stage'] }}"
                         style="max-height: 60vh; min-height: 4rem;">
                        @foreach ($col['students'] as $s)
                            <div data-student-id="{{ $s['id'] }}"
                                 data-edit-url="{{ \App\Filament\Resources\StudentResource::getUrl('edit', ['record' => $s['id']]) }}"
                                 class="fi-kanban-card group block bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md hover:border-emerald-400 dark:hover:border-emerald-500 cursor-grab active:cursor-grabbing transition-all duration-150">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-semibold text-sm text-gray-900 dark:text-gray-100 truncate group-hover:text-emerald-700 dark:group-hover:text-emerald-300">
                                            {{ $s['name'] }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                            {{ $s['phone'] }}
                                        </p>
                                    </div>
                                    @if ($s['owner'])
                                        <span class="shrink-0 inline-flex items-center h-5 px-1.5 rounded bg-gray-100 dark:bg-gray-700 text-[10px] font-medium text-gray-600 dark:text-gray-300">
                                            {{ $s['owner'] }}
                                        </span>
                                    @endif
                                </div>

                                @if ($s['deal'] > 0)
                                    <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-700/60 flex items-center justify-between text-xs">
                                        <span class="tabular-nums font-medium text-gray-700 dark:text-gray-300">
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
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 font-medium">
                                            {{ $s['current_round'] }}
                                        </span>
                                    @endif
                                    <span>{{ $s['days_in_stage'] }}d</span>
                                </div>
                            </div>
                        @endforeach

                        @if ($col['count'] === 0)
                            <div class="h-16 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-md flex items-center justify-center">
                                <span class="text-xs text-gray-400 dark:text-gray-600 italic">Drop here</span>
                            </div>
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
                            evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex] ?? null);
                        }
                    },
                });
            });

            root.addEventListener('click', (e) => {
                const card = e.target.closest('.fi-kanban-card');
                if (!card) return;
                const url = card.dataset.editUrl;
                if (url) window.location.href = url;
            });

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
        .fi-kanban-ghost { opacity: .4; transform: rotate(1deg); }
        .fi-kanban-card:active { transform: rotate(2deg); }
    </style>
</x-filament-panels::page>
