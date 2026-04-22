<x-filament-panels::page>
    @php($r = $this->getReport())

    <p class="text-sm text-gray-600 dark:text-gray-400">
        Counts exclude students still in the <strong>Lead Captured</strong> stage, showing only leads that have progressed past initial capture.
    </p>

    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Total leads past Lead Captured</div>
            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1 tabular-nums">
                {{ $r['totals']['past_capture'] }}
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Owners with activity</div>
            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1 tabular-nums">
                {{ $r['totals']['owners_counted'] }}
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Referrers with activity</div>
            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1 tabular-nums">
                {{ $r['totals']['referrers_counted'] }}
            </div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ([['By owner', $r['byOwner']], ['By referrer', $r['byReferrer']]] as [$heading, $rows])
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
                        @forelse ($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="px-4 py-2">{{ $row['name'] }}</td>
                                <td class="text-right px-4 py-2 tabular-nums">{{ $row['active'] }}</td>
                                <td class="text-right px-4 py-2 tabular-nums text-emerald-600 dark:text-emerald-400">{{ $row['admitted'] }}</td>
                                <td class="text-right px-4 py-2 tabular-nums text-gray-500">{{ $row['closed'] }}</td>
                                <td class="text-right px-4 py-2 tabular-nums font-medium">{{ $row['count'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">No leads past Lead Captured yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
