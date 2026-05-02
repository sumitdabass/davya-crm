<x-filament-panels::page>
    @php
        $report = $this->getReport();
        $rows = $report['rows'];
    @endphp

    <div class="flex items-center gap-3 mb-4">
        <label for="month" class="text-sm font-medium text-gray-700 dark:text-gray-300">Month</label>
        <select wire:model.live="month" id="month"
                class="rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm">
            @foreach ($this->getMonthOptions() as $opt)
                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
            @endforeach
        </select>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            {{ $report['periodStart'] }} → {{ $report['periodEnd'] }}
        </span>
    </div>

    @if (count($rows) === 0)
        <div class="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 p-8 text-center">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                No scores yet for this month. Click <strong>Recalculate now</strong> above.
            </p>
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
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
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                    @foreach ($rows as $r)
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

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Score weights: closed-won 25% · deal amount 25% · rank probability 15% · advance received 10% ·
            conversion rate 10% · meeting-win rate 5% · pipeline health 10%.
            Recalculated nightly at 02:30 IST.
        </p>
    @endif
</x-filament-panels::page>
