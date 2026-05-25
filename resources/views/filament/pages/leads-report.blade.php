<x-filament-panels::page>
    <x-crumbs :trail="[['label' => 'Reports', 'href' => null]]" />
    @php
        $r = $this->getReport();
        $studentsBase = \App\Filament\Resources\StudentResource::getUrl('index');
        $studentsUrl = function (array $params) use ($studentsBase) {
            $tableFilters = [];
            foreach ($params as $k => $v) {
                $tableFilters[$k] = ['value' => $v];
            }
            return $studentsBase . '?' . http_build_query(['tableFilters' => $tableFilters]);
        };
    @endphp

    <p class="text-sm text-gray-600 dark:text-gray-400">
        Counts exclude students still in the <strong>Lead Captured</strong> stage, showing only leads that have progressed past initial capture.
    </p>

    @php $spark = $this->getPastCaptureSpark(); @endphp
    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-3">
        <a href="{{ $studentsUrl(['pipeline_status' => 'past_capture']) }}"
            class="block rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 hover:border-primary-400 hover:shadow-sm transition">
            <div class="text-xs text-gray-500 dark:text-gray-400">Total leads past Lead Captured</div>
            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1 tabular-nums">
                {{ $r['totals']['past_capture'] }}
            </div>
            <x-book-kpi-meta :delta="$spark['delta_pct']" :prior-label="$spark['prior_label']" :series="$spark['series']" />
        </a>
        <div class="block rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Owners with activity</div>
            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1 tabular-nums">
                {{ $r['totals']['owners_counted'] }}
            </div>
        </div>
        <div class="block rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Referrers with activity</div>
            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1 tabular-nums">
                {{ $r['totals']['referrers_counted'] }}
            </div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ([['By owner', $r['byOwner'], 'owner_id'], ['By referrer', $r['byReferrer'], 'referrer_id']] as [$heading, $rows, $userKey])
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden">
                <header class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm">{{ $heading }}</header>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/40 text-xs text-gray-600 dark:text-gray-400">
                        <tr>
                            <th class="text-left  px-4 py-2 font-medium">Name</th>
                            <th class="text-right px-4 py-2 font-medium" title="In-pipeline stages between Meeting Scheduled and Full Payment Received">Active</th>
                            <th class="text-right px-4 py-2 font-medium" title="Admission Confirmed">Admitted</th>
                            <th class="text-right px-4 py-2 font-medium">Closed</th>
                            <th class="text-right px-4 py-2 font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $uid => $row)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="px-4 py-2">
                                    <a href="{{ $studentsUrl([$userKey => $uid, 'pipeline_status' => 'past_capture']) }}"
                                       class="text-primary-600 dark:text-primary-400 hover:underline">{{ $row['name'] }}</a>
                                </td>
                                <td class="text-right px-4 py-2 tabular-nums">
                                    <a href="{{ $studentsUrl([$userKey => $uid, 'pipeline_status' => 'active']) }}" class="hover:underline">{{ $row['active'] }}</a>
                                </td>
                                <td class="text-right px-4 py-2 tabular-nums text-emerald-600 dark:text-emerald-400">
                                    <a href="{{ $studentsUrl([$userKey => $uid, 'pipeline_status' => 'admitted']) }}" class="hover:underline">{{ $row['admitted'] }}</a>
                                </td>
                                <td class="text-right px-4 py-2 tabular-nums text-gray-500">
                                    <a href="{{ $studentsUrl([$userKey => $uid, 'pipeline_status' => 'closed_lost']) }}" class="hover:underline">{{ $row['closed'] }}</a>
                                </td>
                                <td class="text-right px-4 py-2 tabular-nums font-medium">
                                    <a href="{{ $studentsUrl([$userKey => $uid, 'pipeline_status' => 'past_capture']) }}" class="hover:underline">{{ $row['count'] }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">No leads past Lead Captured yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    {{-- Staff Performance section --}}
    @php
        $perf = $this->getPerformanceReport();
        $perfRows = $perf['rows'];
    @endphp

    <div class="mt-10">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Staff Performance</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Score: closed-won 25% · deal amount 25% · rank probability 15% · advance received 10% ·
                    conversion rate 10% · meeting-win rate 5% · pipeline health 10%. Recalculated nightly at 02:30 IST.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <label for="performanceMonth" class="text-sm font-medium text-gray-700 dark:text-gray-300">Month</label>
                <select wire:model.live="performanceMonth" id="performanceMonth"
                        class="rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm">
                    @foreach ($this->getPerformanceMonthOptions() as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if (count($perfRows) === 0)
            <div class="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 p-8 text-center">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    No scores yet for {{ $perf['periodStart'] }} – {{ $perf['periodEnd'] }}. Click <strong>Recalculate scores</strong> at the top of the page.
                </p>
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/40">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-200">Counsellor</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-200">Score</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-200">Tier</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-200">Won</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-200">Deal Won ₹</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-200">Advance ₹</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-200">Conv %</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-200">Open</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-200">Balance ₹</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-200">Rank prob</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($perfRows as $r)
                            <tr>
                                <td class="px-3 py-2">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $r['user_name'] }}</div>
                                    @if ($r['team_head'])
                                        <div class="text-xs text-gray-500">team: {{ $r['team_head'] }}</div>
                                    @endif
                                    @if ($r['is_freelancer'])
                                        <div class="text-xs text-gray-500">freelancer</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums font-semibold text-gray-900 dark:text-gray-100">{{ $r['score'] }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $this->tierColor($r['tier']) }}">
                                        {{ $r['tier'] }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $r['closed_won'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($r['deal_won_amount']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($r['advance_received']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format(((float) $r['conversion_rate']) * 100, 0) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $r['open_leads'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums {{ $r['balance_amount'] > 0 ? 'text-rose-600 dark:text-rose-400' : '' }}">
                                    {{ number_format($r['balance_amount']) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $r['rank_prob_avg'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
