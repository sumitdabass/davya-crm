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
        <div class="text-gray-500 text-sm">Rules tab — populated in Task 18.</div>
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
</x-filament-panels::page>
