<x-filament-panels::page>
    @php
        $kpis = $this->getKpis();
        $rollups = $this->getSectionRollups();
        $assets = $this->getAssetRegister();
        $loans = $this->getLoansOutstanding();
    @endphp

    <div class="mb-4 flex items-center gap-3">
        <div class="text-2xl font-semibold">{{ $company->name }}</div>
        <div class="px-3 py-1 rounded-full bg-emerald-100 text-emerald-800 text-sm font-medium">
            FY {{ $fy->label }} {{ $fy->is_closed ? '(closed)' : '' }}
        </div>
    </div>

    @php
        $companySlug = $company->slug;
        $fyLabel = $fy->label;
        $defaultGenericSlug = $this->defaultGenericSection()?->slug;
        $assetSlug = $this->assetSection()?->slug;
        $priorFy = $this->priorFyLabel();

        $tiles = [
            ['key' => 'total_income',     'label' => 'Total Income',
             'href' => url("/admin/books/{$companySlug}/{$fyLabel}/income"),
             'tooltip' => null],
            ['key' => 'cash_outflow',     'label' => 'Cash Outflow',
             'href' => $defaultGenericSlug ? url("/admin/books/{$companySlug}/{$fyLabel}/section/{$defaultGenericSlug}") : null,
             'tooltip' => null],
            ['key' => 'non_cash_outflow', 'label' => 'Non-Cash (Dep)',
             'href' => $assetSlug ? url("/admin/books/{$companySlug}/{$fyLabel}/section/{$assetSlug}") : null,
             'tooltip' => null],
            ['key' => 'total_outflow',    'label' => 'Total Outflow',
             'href' => $defaultGenericSlug ? url("/admin/books/{$companySlug}/{$fyLabel}/section/{$defaultGenericSlug}") : null,
             'tooltip' => null],
            ['key' => 'net_pl',           'label' => 'Net P/L',
             'href' => null, 'tooltip' => 'Total Income + Recoveries − Total Outflow'],
            ['key' => 'cumulative_pl',    'label' => 'Cumulative P/L',
             'href' => null, 'tooltip' => 'Net P/L + Carryover from prior FY'],
        ];
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
        @foreach ($tiles as $tile)
            @if ($tile['href'])
                <a href="{{ $tile['href'] }}" class="block">
                    <div class="p-4 rounded-lg border bg-white hover:shadow-md hover:border-emerald-500 transition cursor-pointer">
                        <div class="text-xs text-gray-500">{{ $tile['label'] }}</div>
                        <div class="text-xl font-semibold">&#8377; {{ number_format($kpis[$tile['key']], 2) }}</div>
                    </div>
                </a>
            @else
                <div class="p-4 rounded-lg border bg-white" @if ($tile['tooltip']) title="{{ $tile['tooltip'] }}" @endif>
                    <div class="text-xs text-gray-500">{{ $tile['label'] }}</div>
                    <div class="text-xl font-semibold">&#8377; {{ number_format($kpis[$tile['key']], 2) }}</div>
                </div>
            @endif
        @endforeach

        {{-- Carryover tile (special: links to prior FY if exists) --}}
        @php
            $carryHref = $priorFy ? url("/admin/books/{$companySlug}/{$priorFy}") : null;
        @endphp
        @if ($carryHref)
            <a href="{{ $carryHref }}" class="block">
                <div class="p-4 rounded-lg border bg-white hover:shadow-md hover:border-emerald-500 transition cursor-pointer">
                    <div class="text-xs text-gray-500">Carryover {{ $kpis['carryover']['estimate'] ? '(estimate)' : '' }}</div>
                    <div class="text-xl font-semibold">&#8377; {{ number_format($kpis['carryover']['value'], 2) }}</div>
                </div>
            </a>
        @else
            <div class="p-4 rounded-lg border bg-white">
                <div class="text-xs text-gray-500">Carryover {{ $kpis['carryover']['estimate'] ? '(estimate)' : '' }}</div>
                <div class="text-xl font-semibold">&#8377; {{ number_format($kpis['carryover']['value'], 2) }}</div>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
        @foreach ($rollups as $r)
            <a href="{{ url('/admin/books/'.$company->slug.'/'.$fy->label.'/section/'.$r['section']->slug) }}"
               class="block p-4 rounded-lg border bg-white hover:shadow-md">
                <div class="font-semibold">{{ $r['section']->name }}</div>
                <div class="text-xs text-gray-500">{{ $r['count'] }} entries</div>
                <div class="mt-2 text-sm">Salary &#8377; {{ number_format($r['salary_total'], 2) }}</div>
                <div class="text-sm">Loan &#8377; {{ number_format($r['loan_total'], 2) }}</div>
                <div class="text-sm">Paid &#8377; {{ number_format($r['paid_total'], 2) }}</div>
            </a>
        @endforeach
    </div>

    @if (count($assets))
        <div class="mb-6">
            <h3 class="font-semibold mb-2">Asset Register</h3>
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left p-2">Asset</th>
                        <th class="text-right p-2">Original</th>
                        <th class="text-right p-2">Dep (This FY)</th>
                        <th class="text-right p-2">Accumulated</th>
                        <th class="text-right p-2">Book Value</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assets as $a)
                        <tr class="border-t hover:bg-gray-50">
                            <td class="p-2">
                                <a href="{{ url('/admin/books/'.$company->slug.'/'.$fy->label.'/section/'.$a['section_slug']) }}"
                                   class="text-emerald-700 hover:underline">{{ $a['name'] }}</a>
                            </td>
                            <td class="p-2 text-right">{{ number_format($a['original'], 2) }}</td>
                            <td class="p-2 text-right">{{ number_format($a['this_year'], 2) }}</td>
                            <td class="p-2 text-right">{{ number_format($a['accumulated'], 2) }}</td>
                            <td class="p-2 text-right">{{ number_format($a['book_value'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (count($loans))
        <div class="mb-6">
            <h3 class="font-semibold mb-2">Loans Outstanding</h3>
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left p-2">Counterparty</th>
                        <th class="text-right p-2">Loan</th>
                        <th class="text-right p-2">Received Back</th>
                        <th class="text-right p-2">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($loans as $l)
                        <tr class="border-t hover:bg-gray-50">
                            <td class="p-2">
                                @if ($l['section_slug'])
                                    <a href="{{ url('/admin/books/'.$company->slug.'/'.$fy->label.'/section/'.$l['section_slug']) }}"
                                       class="text-emerald-700 hover:underline">{{ $l['title'] }}</a>
                                @else
                                    {{ $l['title'] }}
                                @endif
                            </td>
                            <td class="p-2 text-right">{{ number_format($l['loan'], 2) }}</td>
                            <td class="p-2 text-right">{{ number_format($l['received_back'], 2) }}</td>
                            <td class="p-2 text-right font-semibold">
                                {{ number_format($l['outstanding'], 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
