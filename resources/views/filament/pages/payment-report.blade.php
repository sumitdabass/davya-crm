<x-filament-panels::page>
    <div class="flex gap-2 mb-4 border-b border-gray-200 dark:border-gray-700">
        <button type="button"
            wire:click="setTab('report')"
            @class([
                'px-3 py-1.5 text-sm font-medium border-b-2 -mb-px',
                'border-primary-500 text-primary-600' => $activeTab === 'report',
                'border-transparent text-gray-500 hover:text-gray-700' => $activeTab !== 'report',
            ])>
            Report
        </button>
        <button type="button"
            wire:click="setTab('today')"
            @class([
                'px-3 py-1.5 text-sm font-medium border-b-2 -mb-px',
                'border-primary-500 text-primary-600' => $activeTab === 'today',
                'border-transparent text-gray-500 hover:text-gray-700' => $activeTab !== 'today',
            ])>
            Today Received
        </button>
    </div>

    <div x-show="$wire.activeTab === 'report'" x-cloak>
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>

        @php($r = $this->getReport())

        <div class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-3">
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                <div class="text-xs text-gray-500 dark:text-gray-400">Received</div>
                <div class="text-2xl font-semibold text-emerald-600 dark:text-emerald-400 mt-1 tabular-nums">
                    ₹{{ number_format($r['totals']['received'], 0, '.', ',') }}
                </div>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                <div class="text-xs text-gray-500 dark:text-gray-400">Refunds</div>
                <div class="text-2xl font-semibold text-amber-600 dark:text-amber-400 mt-1 tabular-nums">
                    ₹{{ number_format(abs($r['totals']['refunds']), 0, '.', ',') }}
                </div>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                <div class="text-xs text-gray-500 dark:text-gray-400">Net collected</div>
                <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1 tabular-nums">
                    ₹{{ number_format($r['totals']['net'], 0, '.', ',') }}
                </div>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                <div class="text-xs text-gray-500 dark:text-gray-400">Payment count</div>
                <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1 tabular-nums">
                    {{ $r['totals']['count'] }}
                </div>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm">By owner</header>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/40 text-xs text-gray-600 dark:text-gray-400">
                        <tr>
                            <th class="text-left px-4 py-2 font-medium">Owner</th>
                            <th class="text-right px-4 py-2 font-medium">Received</th>
                            <th class="text-right px-4 py-2 font-medium">Refunds</th>
                            <th class="text-right px-4 py-2 font-medium">#</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($r['byOwner'] as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="px-4 py-2">{{ $row['name'] }}</td>
                                <td class="text-right px-4 py-2 tabular-nums text-emerald-600 dark:text-emerald-400">
                                    ₹{{ number_format($row['received'], 0, '.', ',') }}
                                </td>
                                <td class="text-right px-4 py-2 tabular-nums text-amber-600 dark:text-amber-400">
                                    ₹{{ number_format(abs($row['refunds']), 0, '.', ',') }}
                                </td>
                                <td class="text-right px-4 py-2 tabular-nums">{{ $row['count'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">No payments in range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm">By payment type</header>
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ($r['byType'] as $type => $amt)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="px-4 py-2 capitalize">{{ $type }}</td>
                                <td class="text-right px-4 py-2 tabular-nums {{ $amt < 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                                    ₹{{ number_format(abs($amt), 0, '.', ',') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div x-show="$wire.activeTab === 'today'" x-cloak>
        <div class="flex justify-end mb-2">
            <x-filament::button wire:click="downloadTodayCsv" icon="heroicon-o-arrow-down-tray" size="sm">
                Download CSV
            </x-filament::button>
        </div>

        @if(count($this->todayRows) === 0)
            <div class="text-sm text-gray-400 py-4">No payments received today.</div>
        @else
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500">
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="py-1 pr-2">Time</th>
                        <th class="py-1 pr-2">Student</th>
                        <th class="py-1 pr-2 text-right">Amount</th>
                        <th class="py-1 pr-2">Mode</th>
                        <th class="py-1 pr-2">Type</th>
                        <th class="py-1 pr-2">Owner</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->todayRows as $r)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1 pr-2 font-mono">{{ $r['time'] }}</td>
                            <td class="py-1 pr-2">{{ $r['student_name'] }}</td>
                            <td class="py-1 pr-2 text-right font-mono">₹{{ number_format($r['amount'], 2, '.', ',') }}</td>
                            <td class="py-1 pr-2 uppercase text-xs">{{ $r['mode'] ?? '—' }}</td>
                            <td class="py-1 pr-2 uppercase text-xs">{{ $r['type'] }}</td>
                            <td class="py-1 pr-2">{{ $r['owner_name'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>
