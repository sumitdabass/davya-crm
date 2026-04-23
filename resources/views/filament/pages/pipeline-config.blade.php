{{-- resources/views/filament/pages/pipeline-config.blade.php --}}
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
@endpush
<x-filament-panels::page>
    <div class="flex gap-2 border-b border-gray-200 mb-6">
        <button wire:click="$set('activeTab', 'stages')" class="px-4 py-2 text-sm font-medium {{ $activeTab === 'stages' ? 'text-emerald-600 border-b-2 border-emerald-600' : 'text-gray-500' }}">Stages</button>
        <button wire:click="$set('activeTab', 'rules')" class="px-4 py-2 text-sm font-medium {{ $activeTab === 'rules' ? 'text-emerald-600 border-b-2 border-emerald-600' : 'text-gray-500' }}">Transition Rules</button>
    </div>

    @php($buckets = $this->getStagesByType())
    @php($total = $buckets['open']->count() + $buckets['won']->count() + $buckets['lost']->count())
    @php($stageCounts = $this->getStageStudentCounts())
    @php($allStages = $buckets['open']->concat($buckets['won'])->concat($buckets['lost']))

    @if ($activeTab === 'stages')
        <div class="bg-white border border-gray-200 rounded-lg p-5">
            <div class="flex items-center gap-2 mb-1">
                <span class="text-amber-500">★</span>
                <h3 class="text-base font-semibold">{{ $this->getPipeline()->name }}</h3>
            </div>
            <p class="text-xs text-gray-500 mb-4">{{ $total }} of 20 stages used</p>

            @foreach (['open' => 'Open Stages', 'won' => 'Won Stages', 'lost' => 'Lost Stages'] as $key => $label)
                <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold mt-4 mb-2">{{ $label }}</div>
                <div
                    x-data
                    x-init="new Sortable($el, {
                        animation: 150,
                        handle: '.grip',
                        onEnd: (e) => {
                            const thisIds = Array.from($el.children).map(c => parseInt(c.dataset.stageId, 10));
                            const all = [];
                            document.querySelectorAll('[data-stage-section]').forEach(sec => {
                                if (sec === $el) {
                                    all.push(...thisIds);
                                } else {
                                    Array.from(sec.children).forEach(c => all.push(parseInt(c.dataset.stageId, 10)));
                                }
                            });
                            $wire.reorderStages(all);
                        }
                    })"
                    data-stage-section="{{ $key }}"
                >
                    @foreach ($buckets[$key] as $stage)
                        <div data-stage-id="{{ $stage->id }}" class="flex items-center gap-3 px-3 py-2 border border-gray-200 rounded mb-1.5" wire:key="stage-{{ $stage->id }}">
                            <span class="grip text-gray-300 text-sm tracking-widest select-none cursor-grab">⋮⋮</span>
                            <span class="flex-1 text-sm font-medium text-gray-800">{{ $stage->name }}</span>
                            @if ($stage->stage_type === 'CLOSED_WON') <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800">Won</span> @endif
                            @if ($stage->stage_type === 'CLOSED_LOST') <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-red-100 text-red-800">Lost</span> @endif
                            <button type="button"
                                @click="$dispatch('open-rename-stage', { stageId: {{ $stage->id }}, currentName: @js($stage->name) })"
                                class="px-2 py-1 text-xs font-medium text-gray-600 hover:text-emerald-700 hover:bg-emerald-50 rounded border border-gray-200">Rename</button>
                            <button type="button"
                                @click="$dispatch('open-delete-stage', { stageId: {{ $stage->id }}, stageName: @js($stage->name), studentCount: {{ (int) ($stageCounts[$stage->id] ?? 0) }} })"
                                class="px-2 py-1 text-xs font-medium rounded"
                                style="background-color: #fff; color: #dc2626; border: 1px solid #fecaca;">Delete</button>
                        </div>
                    @endforeach
                </div>
                @php($capHit = $total >= 20)
                @php($bucketType = $key === 'open' ? 'OPEN' : ($key === 'won' ? 'CLOSED_WON' : 'CLOSED_LOST'))
                <button
                    x-on:click.stop="$dispatch('open-stage-modal', { type: '{{ $bucketType }}' })"
                    @disabled($capHit)
                    class="text-sm font-medium text-blue-600 hover:underline px-2 py-1 {{ $capHit ? 'opacity-40 cursor-not-allowed' : '' }}">
                    + Stage
                </button>
            @endforeach
        </div>
    @else
        <div class="flex justify-end mb-4">
            <button class="text-white px-4 py-2 rounded text-sm font-semibold" style="background-color: #059669;" wire:click="$dispatch('open-rule-editor', { ruleId: null })">+ Add Rule</button>
        </div>
        @foreach ($this->getTransitionRules() as $rule)
            <div class="bg-white border border-gray-200 rounded-lg p-4 mb-2" wire:key="rule-{{ $rule->id }}">
                <div class="flex items-center gap-2 mb-2">
                    <span class="font-semibold text-sm flex-1">{{ $rule->name }}</span>
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $rule->severity === 'HARD' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-900' }}">
                        {{ $rule->severity === 'HARD' ? 'Hard · Blocks' : 'Soft · Warns' }}
                    </span>
                    <button
                        wire:click="toggleRule({{ $rule->id }})"
                        title="Toggle active"
                        aria-label="{{ $rule->is_active ? 'Deactivate rule' : 'Activate rule' }}"
                        class="px-2 py-0.5 rounded-full text-[11px] font-semibold cursor-pointer hover:ring-2 hover:ring-offset-1 hover:ring-current {{ $rule->is_active ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        {{ $rule->is_active ? 'Active' : 'Inactive' }}
                    </button>
                    <button
                        wire:click="deleteRule({{ $rule->id }})"
                        wire:confirm="Delete this rule? This cannot be undone."
                        title="Delete rule"
                        class="text-red-500 hover:text-red-700 px-2 text-sm">✕</button>
                </div>
                <div class="text-sm text-gray-600 mb-2">
                    <span class="px-2 py-1 rounded {{ $rule->from_stage_id ? 'bg-gray-100' : 'bg-indigo-50 italic text-indigo-800' }}">
                        {{ $rule->fromStage?->name ?? 'Any stage' }}
                    </span>
                    →
                    <span class="px-2 py-1 rounded {{ $rule->to_stage_id ? 'bg-gray-100' : 'bg-indigo-50 italic text-indigo-800' }}">
                        {{ $rule->toStage?->name ?? 'Any stage' }}
                    </span>
                </div>
                @foreach ($rule->conditions as $cond)
                    <div class="text-xs text-gray-500 border-t border-dashed border-gray-200 pt-2 mt-2">
                        <b>IF</b>
                        @if ($cond->condition_type === 'FIELD_CHECK')
                            field <code class="bg-gray-100 px-1 rounded">{{ $cond->field_or_relation }}</code> {{ $cond->operator }}
                            @if (is_array($cond->value) && isset($cond->value['rhs'])) <code class="bg-gray-100 px-1 rounded">{{ $cond->value['rhs'] }}</code> @endif
                        @else
                            record has ≥{{ $cond->value['count_min'] ?? 1 }} <code class="bg-gray-100 px-1 rounded">{{ $cond->field_or_relation }}</code>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif

    <div x-data="{ open: false, type: 'OPEN', name: '' }"
         x-on:open-stage-modal.window="open = true; type = $event.detail.type; name = ''">
        <div x-show="open" x-cloak class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
            <div class="bg-white rounded-lg p-6 w-96 shadow-xl" @click.outside="open = false">
                <h3 class="font-semibold mb-3">New Stage</h3>
                <input x-model="name" class="w-full border border-gray-300 rounded px-3 py-2 text-sm mb-3" placeholder="Stage name">
                <div class="flex justify-end gap-2">
                    <button @click="open = false" class="px-3 py-1.5 text-sm">Cancel</button>
                    <button
                        @click="$wire.createStage(name, type).then(() => open = false)"
                        class="px-3 py-1.5 text-sm text-white rounded" style="background-color: #059669;">Create</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Rename-stage modal (Follow-up B) --}}
    <div x-data="{ open: false, stageId: null, name: '' }"
         x-on:open-rename-stage.window="open = true; stageId = $event.detail.stageId; name = $event.detail.currentName">
        <div x-show="open" x-cloak class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
            <div class="bg-white rounded-lg p-6 w-96 shadow-xl" @click.outside="open = false">
                <h3 class="font-semibold mb-3">Rename Stage</h3>
                <input x-model="name" class="w-full border border-gray-300 rounded px-3 py-2 text-sm mb-3" placeholder="New name">
                <div class="flex justify-end gap-2">
                    <button @click="open = false" class="px-3 py-1.5 text-sm">Cancel</button>
                    <button
                        @click="$wire.renameStage(stageId, name).then(() => open = false)"
                        class="px-3 py-1.5 text-sm text-white rounded" style="background-color: #059669;">Save</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Delete-stage modal (with transfer dropdown when students exist) --}}
    <div x-data="{ open: false, stageId: null, stageName: '', studentCount: 0, transferTo: '' }"
         x-on:open-delete-stage.window="open = true; stageId = $event.detail.stageId; stageName = $event.detail.stageName; studentCount = $event.detail.studentCount; transferTo = ''">
        <div x-show="open" x-cloak class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
            <div class="bg-white rounded-lg p-6 w-96 shadow-xl" @click.outside="open = false">
                <h3 class="font-semibold mb-2">Delete Stage</h3>
                <p class="text-sm text-gray-700 mb-3">
                    Delete <span class="font-semibold" x-text="stageName"></span>?
                </p>
                <div x-show="studentCount > 0" class="mb-3">
                    <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2 mb-2">
                        <span x-text="studentCount"></span> student(s) are currently in this stage. Pick a stage to move them to:
                    </p>
                    <select x-model="transferTo" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                        <option value="">— Select target stage —</option>
                        @foreach ($allStages as $st)
                            <option value="{{ $st->id }}">{{ $st->name }}{{ $st->stage_type === 'CLOSED_WON' ? ' (Won)' : ($st->stage_type === 'CLOSED_LOST' ? ' (Lost)' : '') }}</option>
                        @endforeach
                    </select>
                </div>
                <p x-show="studentCount === 0" class="text-sm text-gray-500 mb-3">No students are in this stage. This action can't be undone.</p>
                <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
                    <button type="button" @click="open = false" class="px-3 py-1.5 text-sm rounded hover:bg-gray-100">Cancel</button>
                    <button type="button"
                        @click="if (studentCount > 0 && !transferTo) { alert('Pick a target stage for the students first.'); return; } $wire.deleteStage(stageId, transferTo ? Number(transferTo) : null).then(() => open = false)"
                        class="px-4 py-1.5 text-sm font-semibold text-white rounded"
                        style="background-color: #dc2626;">Delete stage</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Rule editor modal — simplified picker UX --}}
    @php($studentFields = [
        'close_reason' => 'Close reason',
        're_entry_reason' => 'Re-entry reason',
        'student_response' => 'Student response',
        'deal_amount' => 'Deal amount',
        'course' => 'Course',
        'category' => 'Category (Delhi/Outside)',
        'plan' => 'Plan (Online/Offline/All)',
        'meeting_date' => 'Meeting date',
        'meeting_location' => 'Meeting location',
        'current_round' => 'Current round',
        'final_college' => 'Final college',
        'final_course' => 'Final course',
        'admission_date' => 'Admission date',
        'is_ipu_registered' => 'IPU registered (yes/no)',
        'ipu_login_code' => 'IPU login code',
        'father_name' => "Father's name",
        'twelfth_marks' => '12th marks',
        'exam_appeared' => 'Exam appeared',
        'refund_amount' => 'Refund amount',
    ])
    @php($studentRelations = [
        'payments' => 'at least one payment',
        'meetings' => 'at least one meeting',
        'roundHistory' => 'at least one round history',
    ])
    <div x-data="{
            open: false,
            form: null,
            defaults() {
                return {
                    name: '',
                    from_stage_id: '',
                    to_stage_id: '',
                    severity: 'HARD',
                    conditions: [{ target: 'close_reason', requirement: 'filled', value: '' }]
                };
            },
            init() { this.form = this.defaults(); },
            addCondition() { this.form.conditions.push({ target: 'close_reason', requirement: 'filled', value: '' }); },
            removeCondition(i) { if (this.form.conditions.length > 1) this.form.conditions.splice(i, 1); }
        }"
        x-on:open-rule-editor.window="form = defaults(); open = true">
        <div x-show="open" x-cloak class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
            <div class="bg-white rounded-lg p-6 w-[560px] max-h-[85vh] overflow-y-auto shadow-xl" @click.outside="open = false">
                <h3 class="font-semibold text-lg mb-4">Create Rule</h3>

                <label class="block text-xs font-medium text-gray-600 mb-1">Rule name</label>
                <input x-model="form.name" placeholder="e.g. Closed needs a reason" class="w-full border border-gray-300 rounded px-3 py-2 text-sm mb-4">

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">From stage (moving out of)</label>
                        <select x-model="form.from_stage_id" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                            <option value="">Any stage</option>
                            @foreach ($allStages as $st)
                                <option value="{{ $st->id }}">{{ $st->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">To stage (moving into)</label>
                        <select x-model="form.to_stage_id" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                            <option value="">Any stage</option>
                            @foreach ($allStages as $st)
                                <option value="{{ $st->id }}">{{ $st->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <p class="text-[11px] text-gray-500 -mt-2 mb-4">At least one side must be a specific stage. Leaving both as "Any stage" is rejected.</p>

                <label class="block text-xs font-medium text-gray-600 mb-2">Student must have (add one or many)</label>
                <template x-for="(c, idx) in form.conditions" :key="idx">
                    <div class="border border-gray-200 rounded p-2 mb-2 bg-gray-50">
                        <div class="flex items-start gap-2">
                            <div class="flex-1 space-y-1">
                                <select x-model="c.target" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
                                    <optgroup label="Student fields">
                                        @foreach ($studentFields as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </optgroup>
                                    <optgroup label="Student relations">
                                        @foreach ($studentRelations as $key => $label)
                                            <option value="relation:{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </optgroup>
                                </select>
                                <div x-show="!c.target.startsWith('relation:')">
                                    <select x-model="c.requirement" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
                                        <option value="filled">must be filled in</option>
                                        <option value="equals">must equal…</option>
                                        <option value="gte">must be at least… (number)</option>
                                    </select>
                                    <input x-show="c.requirement !== 'filled'" x-model="c.value" placeholder="Value (e.g. Ready, 50000)" class="mt-1 w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
                                </div>
                                <p x-show="c.target.startsWith('relation:')" class="text-[11px] text-gray-500">Checked: student has at least one matching record.</p>
                            </div>
                            <button type="button" @click="removeCondition(idx)" x-show="form.conditions.length > 1" class="text-gray-400 hover:text-red-600 px-1 text-lg leading-none mt-0.5">×</button>
                        </div>
                    </div>
                </template>
                <button type="button" @click="addCondition()" class="text-sm text-emerald-700 font-medium hover:underline mb-4">+ Add another requirement</button>

                <label class="block text-xs font-medium text-gray-600 mb-1">If the student doesn't meet these</label>
                <div class="flex gap-4 text-sm mb-4">
                    <label class="flex items-center gap-1.5"><input type="radio" x-model="form.severity" value="HARD"> Block the move</label>
                    <label class="flex items-center gap-1.5"><input type="radio" x-model="form.severity" value="SOFT"> Just warn</label>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <button type="button" @click="open = false" class="px-3 py-1.5 text-sm rounded hover:bg-gray-100">Cancel</button>
                    <button type="button"
                        @click="
                            const conditions = form.conditions.map(c => {
                                const isRelation = c.target.startsWith('relation:');
                                const targetKey = isRelation ? c.target.slice('relation:'.length) : c.target;
                                if (isRelation) return { condition_type: 'HAS_RELATION', field_or_relation: targetKey, operator: 'has_where', value: { count_min: 1 } };
                                if (c.requirement === 'filled') return { condition_type: 'FIELD_CHECK', field_or_relation: targetKey, operator: 'is_not_empty', value: null };
                                if (c.requirement === 'equals') return { condition_type: 'FIELD_CHECK', field_or_relation: targetKey, operator: '=', value: { rhs: c.value } };
                                if (c.requirement === 'gte') return { condition_type: 'FIELD_CHECK', field_or_relation: targetKey, operator: '>=', value: { rhs: Number(c.value) || 0 } };
                            }).filter(Boolean);
                            $wire.saveRule({
                                name: form.name || 'Untitled rule',
                                from_stage_id: form.from_stage_id || null,
                                to_stage_id: form.to_stage_id || null,
                                severity: form.severity,
                                is_active: true,
                                conditions: conditions,
                            }).then(() => open = false);
                        "
                        class="px-4 py-1.5 text-sm font-semibold text-white rounded"
                        style="background-color: #059669;">Save rule</button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
