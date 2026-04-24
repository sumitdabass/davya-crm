<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        These pairs are head-vs-head duplicate phones (Sonam &harr; Nikhil). Pick which record to keep — the other will be deleted and its payments, notes, and round history will move to the keeper.
    </p>

    @php($open = $this->getOpenFlags())
    @php($resolved = $this->getResolvedFlags())

    <div class="mt-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
        <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm flex items-center justify-between">
            <span>Open flags</span>
            <span class="text-xs text-gray-500">{{ $open->count() }} pending</span>
        </header>
        @forelse ($open as $flag)
            @php($a = $flag->studentA)
            @php($b = $flag->studentB)
            @if (config('davyas.visual_v2'))
                <div class="davya-card-row">
                    <div class="text-xs text-gray-500 mb-2">
                        Phone <strong class="text-gray-700 dark:text-gray-300">{{ $flag->phone }}</strong> · flagged {{ $flag->created_at->diffForHumans() }} · reason: {{ $flag->reason }}
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach ([['a', $a, $flag->student_a_id], ['b', $b, $flag->student_b_id]] as [$key, $s, $sid])
                            <div class="rounded border border-gray-200 dark:border-gray-700 p-3">
                                @if ($s)
                                    <div class="font-medium text-sm">{{ $s->name ?? '(no name)' }}</div>
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        owner <strong>{{ $s->owner?->name ?? '—' }}</strong> · source {{ $s->lead_source ?? '—' }} · stage {{ $s->stage }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        id #{{ $s->id }} · created {{ $s->created_at->diffForHumans() }}
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="resolveFlag({{ $flag->id }}, {{ $sid }})"
                                        wire:confirm="Keep this record and delete the other? Notes and payments from the deleted one will move here."
                                        class="mt-3 inline-flex items-center gap-1 rounded-md bg-primary-600 hover:bg-primary-500 text-white text-xs font-medium px-3 py-1.5">
                                        Keep this one
                                    </button>
                                @else
                                    <div class="text-sm text-gray-500 italic">student #{{ $sid }} deleted</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="px-4 py-4 border-t border-gray-100 dark:border-gray-800">
                    <div class="text-xs text-gray-500 mb-2">
                        Phone <strong class="text-gray-700 dark:text-gray-300">{{ $flag->phone }}</strong> · flagged {{ $flag->created_at->diffForHumans() }} · reason: {{ $flag->reason }}
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach ([['a', $a, $flag->student_a_id], ['b', $b, $flag->student_b_id]] as [$key, $s, $sid])
                            <div class="rounded border border-gray-200 dark:border-gray-700 p-3">
                                @if ($s)
                                    <div class="font-medium text-sm">{{ $s->name ?? '(no name)' }}</div>
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        owner <strong>{{ $s->owner?->name ?? '—' }}</strong> · source {{ $s->lead_source ?? '—' }} · stage {{ $s->stage }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        id #{{ $s->id }} · created {{ $s->created_at->diffForHumans() }}
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="resolveFlag({{ $flag->id }}, {{ $sid }})"
                                        wire:confirm="Keep this record and delete the other? Notes and payments from the deleted one will move here."
                                        class="mt-3 inline-flex items-center gap-1 rounded-md bg-primary-600 hover:bg-primary-500 text-white text-xs font-medium px-3 py-1.5">
                                        Keep this one
                                    </button>
                                @else
                                    <div class="text-sm text-gray-500 italic">student #{{ $sid }} deleted</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @empty
            <div class="px-4 py-6 text-sm text-center text-gray-500">No open flags. 🎉</div>
        @endforelse
    </div>

    @if ($resolved->isNotEmpty())
        <div class="mt-6 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
            <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm">
                Recently resolved
            </header>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/40 text-xs text-gray-600 dark:text-gray-400">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium">Phone</th>
                        <th class="text-left px-4 py-2 font-medium">Kept</th>
                        <th class="text-left px-4 py-2 font-medium">Resolved by</th>
                        <th class="text-right px-4 py-2 font-medium">When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resolved as $flag)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="px-4 py-2">{{ $flag->phone }}</td>
                            <td class="px-4 py-2">#{{ $flag->kept_student_id ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $flag->resolvedBy?->name ?? '—' }}</td>
                            <td class="text-right px-4 py-2 text-gray-500">{{ $flag->resolved_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
