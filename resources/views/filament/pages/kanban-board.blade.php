<x-filament-panels::page>
    @php($opts = $this->getFilterOptions())
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
    @php($selectClass = 'block rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500')
    @php($activeFilters = filled($this->filterOwner) || filled($this->filterCourse) || filled($this->filterRound) || filled($this->filterLeadSource) || $this->filterHasPending)

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-4 py-3 shadow-sm">
        <div class="flex flex-col gap-1">
            <label for="kanban-filter-owner" class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Owner</label>
            <select id="kanban-filter-owner" wire:model.live="filterOwner" class="{{ $selectClass }}">
                <option value="">All owners</option>
                @foreach ($opts['owners'] as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label for="kanban-filter-course" class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Course</label>
            <select id="kanban-filter-course" wire:model.live="filterCourse" class="{{ $selectClass }}">
                <option value="">All courses</option>
                @foreach ($opts['courses'] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label for="kanban-filter-round" class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Round</label>
            <select id="kanban-filter-round" wire:model.live="filterRound" class="{{ $selectClass }}">
                <option value="">All rounds</option>
                @foreach ($opts['rounds'] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label for="kanban-filter-source" class="text-[11px] font-medium text-gray-600 dark:text-gray-400">Lead source</label>
            <select id="kanban-filter-source" wire:model.live="filterLeadSource" class="{{ $selectClass }}">
                <option value="">All sources</option>
                @foreach ($opts['sources'] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 self-center pb-[2px]">
            <input type="checkbox" wire:model.live="filterHasPending" class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
            Has pending amount
        </label>

        @if ($activeFilters)
            <button type="button" wire:click="resetFilters"
                    class="ml-auto text-xs font-medium text-gray-600 dark:text-gray-300 hover:text-emerald-700 dark:hover:text-emerald-400 underline underline-offset-2">
                Clear filters
            </button>
        @endif
    </div>

    <div class="fi-kanban-scroll overflow-x-auto pb-4" style="min-height: 70vh;"
         x-data
         x-init="
            // SortableJS loaded globally via AdminPanelProvider HEAD_END.
            // Defer attribute means we may run before it's parsed; poll briefly.
            (function init(){
                if (window.Sortable) { wireKanban($el, $wire); return; }
                const t = setInterval(() => {
                    if (window.Sortable) { clearInterval(t); wireKanban($el, $wire); }
                }, 30);
            })();
         ">
        <div class="flex gap-4 items-start" style="min-width: max-content;">
            @foreach ($board as $col)
                @if (config('davyas.visual_v2'))
                    <div class="davya-kanban-col"
                         data-stage-type="{{ $col['stage_type'] ?? 'active' }}"
                         wire:key="col-v2-{{ $loop->index }}">

                        <div class="davya-kanban-col-head">
                            <h4>{{ $col['stage'] }}</h4>
                            <span class="davya-kanban-col-count">{{ $col['count'] }}</span>
                        </div>
                        <div class="davya-kanban-col-agg">
                            ₹{{ \App\Support\MoneyFormat::indianShort($col['received_total']) }} received · ₹{{ \App\Support\MoneyFormat::indianShort($col['pending_total']) }} pending
                        </div>

                        <div class="fi-kanban-col-items"
                             data-stage="{{ $col['stage'] }}"
                             style="min-height: 4rem;">
                            @foreach ($col['students'] as $s)
                                <div class="davya-dense-card fi-kanban-card"
                                     data-response="{{ $s['student_response'] ?? 'unknown' }}"
                                     data-student-id="{{ $s['id'] }}"
                                     data-edit-url="{{ \App\Filament\Resources\StudentResource::getUrl('edit', ['record' => $s['id']]) }}"
                                     wire:key="card-v2-{{ $s['id'] }}"
                                     wire:click="$dispatch('open-student-peek', { studentId: {{ $s['id'] }} })">
                                    <div class="n">{{ $s['name'] }}</div>
                                    <div class="chips">{{ $s['course'] ?? '—' }}@if($s['current_round']) · R{{ $s['current_round'] }}@endif</div>
                                    <div class="amt" data-zero="{{ ($s['received'] ?? 0) == 0 ? 'true' : 'false' }}">₹{{ \App\Support\MoneyFormat::indianShort($s['received'] ?? 0) }}</div>
                                    <div class="av" style="background: {{ \App\Support\AvatarColor::forUserId((int) ($s['owner_id'] ?? 0)) }};">
                                        {{ \App\Support\AvatarColor::initials($s['owner_name'] ?? '??') }}
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
                @else
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
                                            <span class="tabular-nums text-emerald-600 dark:text-emerald-400 font-medium">
                                                ₹{{ number_format($s['received'], 0, '.', ',') }} received
                                            </span>
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

                                    @if (! empty($s['extras'] ?? []))
                                        <div class="mt-1 text-xs text-gray-700 dark:text-gray-300 space-y-0.5">
                                            @foreach ($s['extras'] as $line)
                                                <div>{{ $line }}</div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach

                            @if ($col['count'] === 0)
                                <div class="h-16 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-md flex items-center justify-center">
                                    <span class="text-xs text-gray-400 dark:text-gray-600 italic">Drop here</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
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
                            // Return card to original column first
                            evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex] ?? null);
                            // If the backend said fields are missing, open the inline fix-up modal
                            if (res && Array.isArray(res.missing_fields) && res.missing_fields.length > 0) {
                                window.dispatchEvent(new CustomEvent('open-fix-modal', { detail: {
                                    studentId: res.student_id,
                                    studentName: res.student_name,
                                    targetStage: res.target_stage,
                                    missingFields: res.missing_fields,
                                    errorMessages: res.errors || [],
                                }}));
                            }
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

    {{-- Inline fix-up modal — opens when a HARD rule blocks a move because a student field is empty --}}
    @php($fieldLabels = [
        'close_reason' => 'Close reason',
        're_entry_reason' => 'Re-entry reason',
        'student_response' => 'Student response',
        'deal_amount' => 'Deal amount',
        'course' => 'Course',
        'category' => 'Category',
        'plan' => 'Plan',
        'meeting_date' => 'Meeting date (YYYY-MM-DD HH:MM)',
        'meeting_location' => 'Meeting location',
        'current_round' => 'Current round',
        'final_college' => 'Final college',
        'final_course' => 'Final course',
        'admission_date' => 'Admission date (YYYY-MM-DD)',
        'is_ipu_registered' => 'IPU registered (1 or 0)',
        'ipu_login_code' => 'IPU login code',
        'father_name' => "Father's name",
        'twelfth_marks' => '12th marks',
        'exam_appeared' => 'Exam appeared',
        'refund_amount' => 'Refund amount',
    ])
    <div x-data="{
            open: false,
            studentId: null,
            studentName: '',
            targetStage: '',
            missingFields: [],
            errorMessages: [],
            values: {},
            submitting: false,
            labels: @js($fieldLabels)
        }"
        x-on:open-fix-modal.window="
            studentId = $event.detail.studentId;
            studentName = $event.detail.studentName;
            targetStage = $event.detail.targetStage;
            missingFields = $event.detail.missingFields;
            errorMessages = $event.detail.errorMessages;
            values = {};
            missingFields.forEach(f => values[f] = '');
            open = true;
        ">
        <div x-show="open" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
            <div class="bg-white rounded-lg p-6 w-[520px] max-h-[85vh] overflow-y-auto shadow-xl" @click.outside="open = false">
                <h3 class="font-semibold text-lg mb-1">Fill missing details</h3>
                <p class="text-sm text-gray-600 mb-3">
                    Moving <span class="font-semibold" x-text="studentName"></span> to <span class="font-semibold" x-text="targetStage"></span> requires:
                </p>
                <template x-if="errorMessages.length">
                    <ul class="text-xs text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2 mb-3 list-disc list-inside">
                        <template x-for="msg in errorMessages" :key="msg">
                            <li x-text="msg"></li>
                        </template>
                    </ul>
                </template>

                <div class="space-y-3 mb-4">
                    <template x-for="field in missingFields" :key="field">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1" x-text="labels[field] || field"></label>
                            <input x-model="values[field]" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" placeholder="Enter value">
                        </div>
                    </template>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <button type="button" @click="open = false" class="px-3 py-1.5 text-sm rounded hover:bg-gray-100">Cancel</button>
                    <button type="button"
                        x-bind:disabled="submitting"
                        @click="
                            submitting = true;
                            $wire.call('fixAndMove', studentId, targetStage, values).then(res => {
                                submitting = false;
                                if (res && res.ok) {
                                    open = false;
                                    setTimeout(() => window.location.reload(), 300);
                                } else if (res && Array.isArray(res.missing_fields) && res.missing_fields.length > 0) {
                                    missingFields = res.missing_fields;
                                    errorMessages = res.errors || [];
                                    const next = {};
                                    missingFields.forEach(f => { next[f] = values[f] || ''; });
                                    values = next;
                                } else {
                                    open = false;
                                }
                            }).catch(() => { submitting = false; });
                        "
                        class="px-4 py-1.5 text-sm font-semibold text-white rounded disabled:opacity-60"
                        style="background-color: #059669;">Save &amp; move</button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
