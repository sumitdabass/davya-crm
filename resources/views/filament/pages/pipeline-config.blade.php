{{-- resources/views/filament/pages/pipeline-config.blade.php --}}
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
@endpush
<x-filament-panels::page>
    <div class="flex gap-2 border-b border-gray-200 mb-6">
        <button wire:click="$set('activeTab', 'stages')" class="px-4 py-2 text-sm font-medium {{ $activeTab === 'stages' ? 'text-emerald-600 border-b-2 border-emerald-600' : 'text-gray-500' }}">Stages</button>
        <button wire:click="$set('activeTab', 'rules')" class="px-4 py-2 text-sm font-medium {{ $activeTab === 'rules' ? 'text-emerald-600 border-b-2 border-emerald-600' : 'text-gray-500' }}">Transition Rules</button>
    </div>

    @if ($activeTab === 'stages')
        @php($buckets = $this->getStagesByType())
        @php($total = $buckets['open']->count() + $buckets['won']->count() + $buckets['lost']->count())
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
                            <div x-data="{ menu: false }" class="relative">
                                <button @click="menu = !menu" class="text-gray-400 hover:text-gray-700 px-2 text-base leading-none">⋯</button>
                                <div x-show="menu" x-cloak @click.outside="menu = false" class="absolute right-0 mt-1 w-40 bg-white border border-gray-200 rounded shadow-lg z-20 text-sm">
                                    <button
                                        @click="menu = false; $dispatch('open-rename-stage', { stageId: {{ $stage->id }}, currentName: @js($stage->name) })"
                                        class="block w-full text-left px-3 py-2 hover:bg-gray-50">Rename…</button>
                                    @foreach (['OPEN' => ['label' => 'Mark as Open', 'human' => 'Open'], 'CLOSED_WON' => ['label' => 'Mark as Won', 'human' => 'Won'], 'CLOSED_LOST' => ['label' => 'Mark as Lost', 'human' => 'Lost']] as $targetType => $meta)
                                        @if ($stage->stage_type !== $targetType)
                                            <button
                                                @click="menu = false; confirm('Change type to {{ $meta['human'] }}? Students currently in this stage will move to the new column on the Kanban.') && $wire.changeStageType({{ $stage->id }}, '{{ $targetType }}')"
                                                class="block w-full text-left px-3 py-2 hover:bg-gray-50">{{ $meta['label'] }}</button>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
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
            <button class="bg-emerald-600 text-white px-4 py-2 rounded text-sm font-semibold" wire:click="$dispatch('open-rule-editor', { ruleId: null })">+ Add Rule</button>
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
                        class="px-3 py-1.5 text-sm bg-emerald-600 text-white rounded">Create</button>
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
                        class="px-3 py-1.5 text-sm bg-emerald-600 text-white rounded">Save</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Rule editor modal (Task 19) --}}
    <div x-data="{
            open: false,
            defaults() { return { name: '', from_stage_id: '', to_stage_id: '', severity: 'HARD', is_active: true, conditions: [{condition_type: 'FIELD_CHECK', field_or_relation: '', operator: 'is_not_empty', value: null}] }; },
            form: { name: '', from_stage_id: '', to_stage_id: '', severity: 'HARD', is_active: true, conditions: [{condition_type: 'FIELD_CHECK', field_or_relation: '', operator: 'is_not_empty', value: null}] }
        }"
        x-on:open-rule-editor.window="form = defaults(); open = true">
        <div x-show="open" x-cloak class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
            <div class="bg-white rounded-lg p-6 w-[520px] shadow-xl" @click.outside="open = false">
                <h3 class="font-semibold mb-3">New Rule</h3>
                <input x-model="form.name" placeholder="Rule name" class="w-full border rounded px-3 py-2 text-sm mb-2">
                <div class="grid grid-cols-2 gap-2 mb-2">
                    <select x-model="form.from_stage_id" class="border rounded px-2 py-2 text-sm">
                        <option value="">Any (from)</option>
                        @foreach ($this->getStagesByType()['open']->concat($this->getStagesByType()['won'])->concat($this->getStagesByType()['lost']) as $st)
                            <option value="{{ $st->id }}">{{ $st->name }}</option>
                        @endforeach
                    </select>
                    <select x-model="form.to_stage_id" class="border rounded px-2 py-2 text-sm">
                        <option value="">Any (to)</option>
                        @foreach ($this->getStagesByType()['open']->concat($this->getStagesByType()['won'])->concat($this->getStagesByType()['lost']) as $st)
                            <option value="{{ $st->id }}">{{ $st->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-4 text-sm mb-3">
                    <label><input type="radio" x-model="form.severity" value="HARD"> Hard (block)</label>
                    <label><input type="radio" x-model="form.severity" value="SOFT"> Soft (warn)</label>
                </div>
                <template x-for="(c, idx) in form.conditions" :key="idx">
                    <div class="grid grid-cols-[1fr_1fr_1fr_auto] gap-1 mb-2">
                        <select x-model="c.condition_type" class="border rounded text-xs px-2 py-1">
                            <option value="FIELD_CHECK">field</option>
                            <option value="HAS_RELATION">has</option>
                        </select>
                        <input x-model="c.field_or_relation" class="border rounded text-xs px-2 py-1" placeholder="e.g. close_reason or meetings">
                        <input x-model="c.operator" class="border rounded text-xs px-2 py-1" placeholder="is_not_empty, >=, has_where">
                        <button @click="form.conditions.splice(idx, 1)" class="text-red-500 px-2">✕</button>
                    </div>
                </template>
                <button @click="form.conditions.push({condition_type:'FIELD_CHECK',field_or_relation:'',operator:'is_not_empty',value:null})" class="text-blue-600 text-sm mb-3">+ Add condition</button>
                <div class="flex justify-end gap-2">
                    <button @click="open = false" class="px-3 py-1.5 text-sm">Cancel</button>
                    <button @click="$wire.saveRule(form).then(() => open = false)" class="px-3 py-1.5 text-sm bg-emerald-600 text-white rounded">Save rule</button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
