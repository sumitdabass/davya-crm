<x-filament-panels::page>
    <x-crumbs :trail="[['label' => 'Reports', 'href' => null]]" title="Payments" />
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
        <button type="button"
            wire:click="setTab('detail')"
            @class([
                'px-3 py-1.5 text-sm font-medium border-b-2 -mb-px',
                'border-primary-500 text-primary-600' => $activeTab === 'detail',
                'border-transparent text-gray-500 hover:text-gray-700' => $activeTab !== 'detail',
            ])>
            Detail
        </button>
    </div>

    <div x-show="$wire.activeTab === 'report'" x-cloak>
        <form wire:submit="apply">
            {{ $this->form }}

            <div class="mt-4 flex flex-col sm:flex-row sm:justify-end gap-2">
                <button type="submit"
                    wire:loading.attr="disabled"
                    wire:target="apply"
                    class="davya-action davya-action--solid w-full sm:w-auto justify-center disabled:opacity-60">
                    <span wire:loading.remove wire:target="apply">Apply filters</span>
                    <span wire:loading wire:target="apply">Applying…</span>
                </button>
            </div>
        </form>

        @php($r = $this->getReport())

        <div class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-3">
            @php($meta = method_exists($this, 'getKpiMeta') ? $this->getKpiMeta() : [])
            <button type="button" wire:click="setTab('detail')" class="davya-books-kpi" style="cursor:pointer; text-align:left;">
                <div class="davya-books-kpi__label">Received</div>
                <x-book-amount :v="(float) $r['totals']['received']" big />
                <x-book-kpi-meta prior-prefix="vs"
                    :delta="$meta['received']['delta_pct'] ?? null"
                    :prior-label="$meta['received']['prior_label'] ?? null"
                    :series="$meta['received']['series'] ?? []"
                    color="var(--brand-600,#059669)" />
            </button>
            <button type="button" wire:click="setTab('detail', null, 'refund')" class="davya-books-kpi" style="cursor:pointer; text-align:left;">
                <div class="davya-books-kpi__label">Refunds</div>
                <x-book-amount :v="abs((float) $r['totals']['refunds'])" big :danger="abs((float) $r['totals']['refunds']) > 0" />
                <x-book-kpi-meta prior-prefix="vs"
                    :delta="$meta['refunds']['delta_pct'] ?? null"
                    :prior-label="$meta['refunds']['prior_label'] ?? null"
                    :series="$meta['refunds']['series'] ?? []"
                    color="var(--warning,#F59E0B)" />
            </button>
            <button type="button" wire:click="setTab('detail')" class="davya-books-kpi" style="cursor:pointer; text-align:left;">
                <div class="davya-books-kpi__label">Net collected</div>
                <x-book-amount :v="(float) $r['totals']['net']" big />
                <x-book-kpi-meta prior-prefix="vs"
                    :delta="$meta['net']['delta_pct'] ?? null"
                    :prior-label="$meta['net']['prior_label'] ?? null"
                    :series="$meta['net']['series'] ?? []"
                    color="var(--brand-600,#059669)" />
            </button>
            <button type="button" wire:click="setTab('detail')" class="davya-books-kpi" style="cursor:pointer; text-align:left;">
                <div class="davya-books-kpi__label">Payment count</div>
                <div style="font-size:24px; font-weight:600; font-variant-numeric:tabular-nums; margin-top:4px;">{{ $r['totals']['count'] }}</div>
                <x-book-kpi-meta prior-prefix="vs"
                    :delta="$meta['count']['delta_pct'] ?? null"
                    :prior-label="$meta['count']['prior_label'] ?? null"
                    :series="$meta['count']['series'] ?? []"
                    color="var(--text-sub,#6B7280)" />
            </button>
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
                        @forelse ($r['byOwner'] as $ownerId => $row)
                            <tr wire:click="setTab('detail', {{ (int) $ownerId }})"
                                class="border-t border-gray-100 dark:border-gray-800 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/40">
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
                            <tr wire:click="setTab('detail', null, '{{ $type }}')"
                                class="border-t border-gray-100 dark:border-gray-800 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/40">
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
                            <td class="py-1 pr-2">
                                <a href="{{ \App\Filament\Resources\StudentResource::getUrl('edit', ['record' => $r['student_id']]) }}"
                                   class="text-primary-600 dark:text-primary-400 hover:underline">{{ $r['student_name'] }}</a>
                            </td>
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

    <div x-show="$wire.activeTab === 'detail'" x-cloak>
        @php($detailRows = $this->getDetailRows())
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <div class="text-sm text-gray-600 dark:text-gray-400">
                Scope: <span class="font-medium text-gray-900 dark:text-gray-100">{{ $this->getDetailScopeLabel() }}</span>
                @if($detailOwnerId !== null || $detailType !== null)
                    <button type="button" wire:click="setTab('detail')" class="ml-2 text-xs text-primary-600 hover:underline">Clear scope</button>
                @endif
            </div>
            <button type="button" wire:click="setTab('report')" class="text-xs text-gray-500 hover:underline">← Back to summary</button>
        </div>

        @if(count($detailRows) === 0)
            <div class="text-sm text-gray-400 py-4">No payments match the current filter.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500">
                        <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                            <th class="py-1 pr-2">Date</th>
                            <th class="py-1 pr-2">Student</th>
                            <th class="py-1 pr-2 text-right">Amount</th>
                            <th class="py-1 pr-2">Mode</th>
                            <th class="py-1 pr-2">Type</th>
                            <th class="py-1 pr-2">Owner</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($detailRows as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-1 pr-2 font-mono whitespace-nowrap">{{ $row['received_at'] }}</td>
                                <td class="py-1 pr-2">
                                    <a href="{{ \App\Filament\Resources\StudentResource::getUrl('edit', ['record' => $row['student_id']]) }}"
                                       class="text-primary-600 dark:text-primary-400 hover:underline">{{ $row['student_name'] }}</a>
                                </td>
                                <td class="py-1 pr-2 text-right font-mono {{ $row['amount'] < 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                                    ₹{{ number_format(abs($row['amount']), 2, '.', ',') }}
                                </td>
                                <td class="py-1 pr-2 uppercase text-xs">{{ $row['mode'] ?? '—' }}</td>
                                <td class="py-1 pr-2 uppercase text-xs">{{ $row['type'] }}</td>
                                <td class="py-1 pr-2">{{ $row['owner_name'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
