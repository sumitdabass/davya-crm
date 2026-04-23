<x-filament-widgets::widget>
    {{-- Required for $this->scheduleAction to actually open its form modal --}}
    <x-filament-actions::modals />

    <x-filament::section>
        <x-slot name="heading">Meetings — next 5 days</x-slot>
        <x-slot name="headerEnd">
            {{ $this->scheduleAction }}
        </x-slot>

        <div class="grid grid-cols-1 md:grid-cols-5 gap-2">
            @foreach($this->days as $day)
                <div @class([
                    'rounded-lg border p-2',
                    'border-primary-300 bg-primary-50 dark:bg-primary-950/20' => $day['is_today'],
                    'border-gray-200 dark:border-gray-700'                    => ! $day['is_today'],
                ])>
                    <div class="text-xs font-semibold mb-2 text-gray-700 dark:text-gray-200">
                        {{ $day['label'] }}
                        <span class="text-gray-400">({{ count($day['meetings']) }})</span>
                    </div>

                    @forelse($day['meetings'] as $m)
                        <div @class([
                            'rounded border-l-4 px-2 py-1.5 mb-1.5 text-xs bg-white dark:bg-gray-900',
                            'border-blue-400'                   => $m['status'] === 'scheduled' && ! $m['is_overdue'],
                            'border-amber-400'                  => $m['is_overdue'],
                            'border-emerald-400 opacity-60'     => $m['status'] === 'held',
                        ])>
                            <div class="font-medium flex items-center justify-between">
                                <span>{{ $m['time'] }} · {{ \Illuminate\Support\Str::limit($m['student_name'], 18) }}</span>
                                @if($m['is_overdue'])
                                    <span class="text-[10px] font-bold text-amber-700 bg-amber-100 px-1 rounded">OVERDUE</span>
                                @endif
                            </div>
                            <div class="text-gray-500 dark:text-gray-400">
                                {{ $m['course'] ?? '—' }} · {{ $m['mode'] }} · {{ $m['owner_initials'] }}
                                @if($m['student_phone']) · <span class="font-mono">{{ $m['student_phone'] }}</span> @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-xs text-gray-400">—</div>
                    @endforelse
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
