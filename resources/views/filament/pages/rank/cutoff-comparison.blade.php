<x-filament-panels::page>
    {{ $this->form }}

    @php($r = $this->results)

    @if (empty($r['rows']))
        <p class="text-sm text-gray-500 py-6">
            No comparable data for this selection — need two years of cutoffs (a prior year with a final round and a newer year). Try General / Gender-Neutral / Delhi.
        </p>
    @else
        @php($anchor = count($r['anchor_rounds']) === 1 ? 'Round '.$r['anchor_rounds'][0] : 'latest round')
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 p-4 text-sm">
            <p class="text-gray-700 dark:text-gray-300">
                Projecting <span class="font-semibold">{{ $r['newer_year'] }} Round {{ $r['final_round'] }}</span>
                from {{ $r['newer_year'] }} {{ $anchor }}, using each branch's
                <span class="font-semibold">{{ $r['prior_year'] }}</span> {{ $anchor }}→Round {{ $r['final_round'] }} slide.
            </p>
            <p class="mt-1 text-gray-600 dark:text-gray-400">
                <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $r['up'] }}</span> looser (easier) ·
                <span class="font-semibold text-rose-600 dark:text-rose-400">{{ $r['down'] }}</span> tighter (tougher)
                · higher closing rank = easier to get in.
            </p>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-3">Institute</th>
                        <th class="py-2 pr-3">Branch</th>
                        <th class="py-2 pr-3 text-right">{{ $r['prior_year'] }} R{{ $r['final_round'] }}</th>
                        <th class="py-2 pr-3 text-right">{{ $r['newer_year'] }} R{{ $r['anchor_rounds'][0] ?? '' }}</th>
                        <th class="py-2 pr-3 text-right">Proj. {{ $r['newer_year'] }} R{{ $r['final_round'] }}</th>
                        <th class="py-2 pr-3 text-right">Change</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($r['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-3 whitespace-nowrap">{{ $row['institute'] }}</td>
                            <td class="py-2 pr-3">{{ $row['branch'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums text-gray-500">{{ number_format($row['prior_final']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums text-gray-500">{{ number_format($row['newer_anchor']) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums font-semibold">
                                {{ number_format($row['projected']) }}@if($row['is_actual'])<span class="ml-1 text-[10px] text-gray-400" title="Actual final round (not projected)">✓</span>@endif
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums whitespace-nowrap {{ $row['direction'] === 'up' ? 'text-emerald-600 dark:text-emerald-400' : ($row['direction'] === 'down' ? 'text-rose-600 dark:text-rose-400' : 'text-gray-400') }}">
                                {{ $row['delta'] > 0 ? '+' : '' }}{{ number_format($row['delta']) }}
                                <span class="text-xs">({{ $row['pct'] > 0 ? '+' : '' }}{{ $row['pct'] }}%)</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-3 text-xs text-gray-500">
                Projected closing rank = newer-year {{ $anchor }} × (prior-year Round {{ $r['final_round'] }} ÷ prior-year {{ $anchor }}).
                ✓ = newer year's final round is already published, so the figure is actual, not projected.
                Recalculated live — updates automatically as each new round is imported.
            </p>
        </div>
    @endif
</x-filament-panels::page>
