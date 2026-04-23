{{-- resources/views/filament/pages/pipeline-config.blade.php --}}
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
                @foreach ($buckets[$key] as $stage)
                    <div class="flex items-center gap-3 px-3 py-2 border border-gray-200 rounded mb-1.5" wire:key="stage-{{ $stage->id }}">
                        <span class="text-gray-300 text-sm tracking-widest select-none">⋮⋮</span>
                        <span class="flex-1 text-sm font-medium text-gray-800">{{ $stage->name }}</span>
                        @if ($stage->stage_type === 'CLOSED_WON') <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800">Won</span> @endif
                        @if ($stage->stage_type === 'CLOSED_LOST') <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-red-100 text-red-800">Lost</span> @endif
                    </div>
                @endforeach
            @endforeach
        </div>
    @else
        <div class="text-gray-500 text-sm">Rules tab — populated in Task 18.</div>
    @endif
</x-filament-panels::page>
