<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Payments received — today</x-slot>

        @if(count($this->rows) === 0)
            <div class="text-sm text-gray-400">No payments yet today.</div>
        @else
            <div class="davya-table-scroll">
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
                    @foreach($this->rows as $r)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1 pr-2 font-mono">{{ $r['time'] }}</td>
                            <td class="py-1 pr-2">{{ $r['student_name'] }}</td>
                            <td class="py-1 pr-2 text-right font-mono">
                                ₹{{ number_format($r['amount'], 2, '.', ',') }}
                            </td>
                            <td class="py-1 pr-2 uppercase text-xs">{{ $r['mode'] ?? '—' }}</td>
                            <td class="py-1 pr-2 uppercase text-xs">{{ $r['type'] }}</td>
                            <td class="py-1 pr-2">{{ $r['owner_name'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="text-xs text-gray-500">
                        <td colspan="2" class="pt-2 font-semibold">
                            Total · {{ count($this->rows) }} payment{{ count($this->rows) === 1 ? '' : 's' }}
                        </td>
                        <td class="pt-2 text-right font-mono font-semibold">
                            ₹{{ number_format($this->total, 2, '.', ',') }}
                        </td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
