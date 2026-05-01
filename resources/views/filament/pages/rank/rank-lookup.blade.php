<x-filament-panels::page>
    <style>
        .fits-cell { background-color: rgb(220 252 231); font-weight: 600; }
        .dark .fits-cell { background-color: rgba(34,197,94,0.25); }
        .badge-safe     { background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; }
        .badge-probable { background:#fef3c7; color:#a16207; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; }
        .badge-reach    { background:#fee2e2; color:#b91c1c; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; }
        @media print {
            .fi-sidebar, .fi-topbar, .fi-page-header, form, .no-print { display: none !important; }
            .fi-main-ctn { padding: 0 !important; }
            body { background: #fff !important; }
            table { font-size: 11px; }
        }
    </style>

    @php $result = $this->results; @endphp

    <div class="no-print">
        <form wire:submit="submit" class="mb-6">
            {{ $this->form }}
            <div class="mt-4 flex gap-3">
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">Look up</x-filament::button>
                <x-filament::button type="button" color="gray" icon="heroicon-o-printer" onclick="window.print()">Print</x-filament::button>
            </div>
        </form>
    </div>

    @if (! empty($result['rank']))
        @php
            $predLabel = $result['prediction_round'] === 'sliding' ? 'Sliding (R4) Delhi' : 'R3 Delhi';
            $regionLabel = ucfirst(str_replace('_', ' ', $result['user_region'] ?? ''));
            $colleges = $result['colleges'];
            $visible = $result['visible_count'];
            $hidden = max(0, $colleges->count() - $visible);
            $notes = $result['notes'] ?? [];
            $notesPending = ($this->data['aiOn'] ?? false) && count($notes) < $visible;
        @endphp

        @if ($notesPending)
            <div x-data x-init="$nextTick(() => $wire.loadNotes())" wire:key="notes-loader-{{ $result['rank'] }}-{{ $visible }}"></div>
        @endif

        <div class="mb-4">
            <h2 style="font-size:18px; font-weight:bold; margin:0;">Rank Lookup Results</h2>
            <p style="margin:4px 0 0 0; font-size: 13px; color: var(--text-sub, #4b5563);">
                Rank: <strong>{{ number_format($result['rank']) }}</strong> ·
                Student region: <strong>{{ $regionLabel }}</strong> ·
                Year: <strong>{{ $this->data['year'] ?? '' }}</strong> ·
                Prediction basis: <strong>{{ $predLabel }}</strong> ·
                Showing <strong>{{ $visible }}</strong> of <strong>{{ $colleges->count() }}</strong> eligible colleges
            </p>
        </div>

        @if ($colleges->isEmpty())
            <div class="rounded-lg border border-gray-200 bg-white p-6 text-center text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                No matching cutoffs for rank {{ number_format($result['rank']) }} under the current filter.
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-3 py-2 text-left">#</th>
                        <th class="px-3 py-2 text-left">Branch</th>
                        <th class="px-3 py-2 text-left">Bucket</th>
                        <th class="px-3 py-2 text-right">{{ $predLabel }} Max</th>
                        <th class="px-3 py-2 text-right">Cushion</th>
                        <th class="px-3 py-2 text-right">Seats</th>
                        <th class="px-3 py-2 text-right">R1</th>
                        <th class="px-3 py-2 text-right">R2</th>
                        <th class="px-3 py-2 text-right">R3</th>
                        <th class="px-3 py-2 text-right">Sliding</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($colleges->take($visible) as $idx => $col)
                        @php
                            $instId = $col['branches'][0]['institute_id'] ?? null;
                            $note = $notes[$instId] ?? null;
                        @endphp
                        <tr style="background:#f1f5f9;">
                            <td colspan="10" class="px-3 py-2">
                                <strong style="font-size:14px;">{{ $idx + 1 }}. {{ $col['institute'] }}</strong>
                                @if ($note)
                                    <div style="margin-top:2px; font-style:italic; font-size:12px; color:#475569;">{{ $note }}</div>
                                @elseif ($notesPending)
                                    <div style="margin-top:2px; font-style:italic; font-size:12px; color:#94a3b8;">Generating counselling note…</div>
                                @endif
                            </td>
                        </tr>
                        @foreach ($col['branches'] as $b)
                            @php
                                $shift = $b['shift'] ? ' (Shift '.$b['shift'].')' : '';
                                $r1 = $b['rounds']['1']['max'] ?? null;
                                $r2 = $b['rounds']['2']['max'] ?? null;
                                $r3 = $b['rounds']['3']['max'] ?? null;
                                $sl = $b['rounds']['sliding']['max'] ?? null;
                            @endphp
                            <tr>
                                <td class="px-3 py-2"></td>
                                <td class="px-3 py-2">{{ $b['branch'] }}{{ $shift }}</td>
                                <td class="px-3 py-2"><span class="badge-{{ $b['bucket'] }}">{{ ucfirst($b['bucket']) }}</span></td>
                                <td class="px-3 py-2 text-right fits-cell">{{ number_format($b['prediction_max']) }}</td>
                                <td class="px-3 py-2 text-right">{{ ($b['cushion_pct'] >= 0 ? '+' : '').$b['cushion_pct'] }}%</td>
                                <td class="px-3 py-2 text-right">{{ $b['seat_count'] !== null ? number_format($b['seat_count']) : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $r1 ? number_format($r1) : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $r2 ? number_format($r2) : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $r3 ? number_format($r3) : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $sl ? number_format($sl) : '—' }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($hidden > 0)
                <div class="no-print mt-4 text-center">
                    <x-filament::button wire:click="showMore" color="gray" icon="heroicon-o-chevron-down">
                        Show {{ $hidden }} more college{{ $hidden === 1 ? '' : 's' }}
                    </x-filament::button>
                </div>
            @endif
        @endif
    @endif
</x-filament-panels::page>
