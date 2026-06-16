<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}
        <div class="mt-4">
            <x-filament::button type="submit">Predict</x-filament::button>
        </div>
    </form>

    @php($result = $this->results)
    @if ($result['submitted'])
        <div class="mt-6" id="rank-results">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $result['reach_count'] }}</span>
                    option(s) within reach
                </p>
                <x-filament::button color="gray" tag="button" onclick="window.print()" class="print:hidden">
                    Save as PDF / Print
                </x-filament::button>
            </div>

            @if (count($result['rows']) === 0)
                <p class="text-sm text-gray-500 py-6">No options for this selection. Untick “within reach” to see long-shots, or adjust filters.</p>
            @else
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-3">Chance</th>
                            <th class="py-2 pr-3">Institute / Campus</th>
                            <th class="py-2 pr-3">Branch</th>
                            <th class="py-2 pr-3 text-right">Final CR</th>
                            <th class="py-2 pr-3 text-right">R1 CR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result['rows'] as $row)
                            @php($colors = [
                                'SAFE' => 'bg-green-100 text-green-800',
                                'LIKELY' => 'bg-green-100 text-green-800',
                                'BORDERLINE' => 'bg-yellow-100 text-yellow-800',
                                'STRETCH' => 'bg-orange-100 text-orange-800',
                                'UNLIKELY' => 'bg-red-100 text-red-800',
                            ])
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-3">
                                    <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold {{ $colors[$row['chance']] ?? '' }}">{{ $row['chance'] }}</span>
                                </td>
                                <td class="py-2 pr-3">{{ $row['institute'] }}@if($row['women_only']) <span title="Women-only institute">♀</span>@endif</td>
                                <td class="py-2 pr-3">{{ $row['branch'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['final_cr']) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['r1_cr']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-3 text-xs text-gray-500">CR = closing rank (last admitted JEE-Main All-India rank). Final CR is the prediction benchmark; lower = more competitive. ♀ = women-only institute.</p>
            @endif
        </div>
    @endif

    <style>
        @media print {
            .fi-sidebar, .fi-topbar, form, .print\:hidden { display: none !important; }
            #rank-results { margin: 0; }
        }
    </style>
</x-filament-panels::page>
