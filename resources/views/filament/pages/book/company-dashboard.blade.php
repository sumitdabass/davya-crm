<x-filament-panels::page>
    @php
        $kpis = $this->getKpis();
        $rollups = $this->getSectionRollups();
        $assets = $this->getAssetRegister();
        $loans = $this->getLoansOutstanding();
        $visibleRegions = $this->getVisibleRegions();
        $priorFy = $this->priorFyLabel();
        $defaultGenericSlug = $this->defaultGenericSection()?->slug;
        $assetSlug = $this->assetSection()?->slug;
        $companySlug = $company->slug;
        $fyLabel = $fy->label;
    @endphp

    <div class="davya-books-header">
        <a href="{{ url('/admin/books') }}" class="davya-books-header__crumb">Books</a>
        <h1 class="davya-books-header__title">{{ $company->name }}</h1>
        <span class="davya-owner-pill" style="background:var(--brand-50); border-color:var(--brand-100); color:var(--brand-700);">FY {{ $fyLabel }}{{ $fy->is_closed ? ' · closed' : '' }}</span>
    </div>

    {{-- KPI tiles --}}
    @if ($visibleRegions['kpis'])
        <div class="davya-section-card">
            <div class="davya-section-card-title">Year at a glance</div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px;">
                @php
                    $tiles = [
                        ['key'=>'total_income',     'label'=>'Total Income',     'href'=>url("/admin/books/{$companySlug}/{$fyLabel}/income"), 'hint'=>'View income'],
                        ['key'=>'cash_received',    'label'=>'Cash Received',    'href'=>null, 'tooltip'=>'Sum of received-back / inbound payments (info-only — does not change Net P/L)'],
                        ['key'=>'loans_given_outstanding', 'label'=>'Loans Given',   'href'=>url("/admin/books/{$companySlug}/{$fyLabel}/section/loan"),         'hint'=>'Outstanding · owed to us'],
                        ['key'=>'loans_taken_outstanding', 'label'=>'Loans Taken',   'href'=>url("/admin/books/{$companySlug}/{$fyLabel}/section/loans_taken"), 'hint'=>'Outstanding · we owe'],
                        ['key'=>'cash_outflow',     'label'=>'Cash Outflow',     'href'=>$defaultGenericSlug ? url("/admin/books/{$companySlug}/{$fyLabel}/section/{$defaultGenericSlug}") : null, 'hint'=>$defaultGenericSlug ? 'View spend' : null],
                        ['key'=>'non_cash_outflow', 'label'=>'Non-Cash (Dep)',   'href'=>$assetSlug ? url("/admin/books/{$companySlug}/{$fyLabel}/section/{$assetSlug}") : null, 'hint'=>$assetSlug ? 'View assets' : null],
                        ['key'=>'total_outflow',    'label'=>'Total Outflow',    'href'=>$defaultGenericSlug ? url("/admin/books/{$companySlug}/{$fyLabel}/section/{$defaultGenericSlug}") : null, 'hint'=>null],
                        ['key'=>'net_pl',           'label'=>'Net P/L',          'href'=>null, 'tooltip'=>'Total Income − Total Outflow (recoveries shown separately as Cash Received)'],
                        ['key'=>'cumulative_pl',    'label'=>'Cumulative P/L',   'href'=>null, 'tooltip'=>'Net P/L + Carryover from prior FY'],
                    ];
                @endphp
                @foreach ($tiles as $tile)
                    @php
                        $value = $kpis[$tile['key']];
                        $valueClass = $value < 0 ? 'davya-books-kpi__value davya-books-kpi__value--danger' : 'davya-books-kpi__value';
                    @endphp
                    @if ($tile['href'])
                        <a href="{{ $tile['href'] }}" class="davya-books-kpi">
                            <div class="davya-books-kpi__label">{{ $tile['label'] }}</div>
                            <div class="{{ $valueClass }}">&#8377;{{ number_format($value, 2) }}</div>
                            @if (! empty($tile['hint']))
                                <div class="davya-books-kpi__hint">{{ $tile['hint'] }}</div>
                            @endif
                        </a>
                    @else
                        {{-- Non-href tiles (Net P/L / Cumulative P/L / Cash Received) open an explain modal --}}
                        <a class="davya-books-kpi" style="cursor:pointer;"
                           wire:click.prevent="mountAction('explainKpi', { key: '{{ $tile['key'] }}', label: '{{ $tile['label'] }}' })"
                           title="{{ $tile['tooltip'] ?? 'Click to see the math' }}">
                            <div class="davya-books-kpi__label">{{ $tile['label'] }}</div>
                            <div class="{{ $valueClass }}">&#8377;{{ number_format($value, 2) }}</div>
                            <div class="davya-books-kpi__hint">See math</div>
                        </a>
                    @endif
                @endforeach

                {{-- Carryover tile --}}
                @php
                    $carryHref = $priorFy ? url("/admin/books/{$companySlug}/{$priorFy}") : null;
                @endphp
                @if ($carryHref)
                    <a href="{{ $carryHref }}" class="davya-books-kpi">
                        <div class="davya-books-kpi__label">
                            Carryover
                            @if ($kpis['carryover']['estimate'])
                                <span class="davya-books-badge davya-books-badge--warning">estimate</span>
                            @endif
                        </div>
                        <div class="davya-books-kpi__value">&#8377;{{ number_format($kpis['carryover']['value'], 2) }}</div>
                        <div class="davya-books-kpi__hint">View prior FY</div>
                    </a>
                @else
                    <a class="davya-books-kpi" style="cursor:pointer;"
                       wire:click.prevent="mountAction('explainKpi', { key: 'carryover', label: 'Carryover' })">
                        <div class="davya-books-kpi__label">
                            Carryover
                            @if ($kpis['carryover']['estimate'])
                                <span class="davya-books-badge davya-books-badge--warning">estimate</span>
                            @endif
                        </div>
                        <div class="davya-books-kpi__value davya-books-kpi__value--muted">&#8377;{{ number_format($kpis['carryover']['value'], 2) }}</div>
                        <div class="davya-books-kpi__hint">See math</div>
                    </a>
                @endif
            </div>
        </div>
    @endif

    {{-- Section roll-ups --}}
    @if ($visibleRegions['rollups'])
        <div class="davya-section-card">
            <div class="davya-section-card-title">Sections</div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px;">
                @foreach ($rollups as $r)
                    <a href="{{ url('/admin/books/'.$companySlug.'/'.$fyLabel.'/section/'.$r['section']->slug) }}"
                       class="davya-books-roll"
                       data-kind="{{ $r['section']->slug }}">
                        <div class="davya-books-roll__name">{{ $r['section']->name }}</div>
                        <div class="davya-books-roll__count">{{ $r['count'] }} {{ $r['count'] === 1 ? 'entry' : 'entries' }}</div>
                        <div class="davya-books-roll__rows">
                            @php
                                $slug = $r['section']->slug;
                                $isGivenSection = $slug === 'loan';
                                $isTakenSection = $slug === 'loans_taken';
                                $amountLabel = match ($slug) {
                                    'salary' => 'Salary (ann)',
                                    'rent'   => 'Rent (ann)',
                                    default  => 'Amount (ann)',
                                };
                                $loanLabel = $isTakenSection ? 'Principal taken' : 'Loan';
                                // Movement = the cash leg meaningful for this section.
                                if ($isGivenSection) {
                                    $movementLabel = 'Recovered';
                                    $movementValue = $r['received_back_total'];
                                } elseif ($isTakenSection) {
                                    $movementLabel = 'Repaid';
                                    $movementValue = $r['paid_total'];
                                } else {
                                    $movementLabel = 'Paid';
                                    $movementValue = $r['paid_total'];
                                }
                                $balanceLabel = match (true) {
                                    $isGivenSection => 'Outstanding (owed to us)',
                                    $isTakenSection => 'Outstanding (we owe)',
                                    default         => 'Balance',
                                };
                            @endphp
                            @if ($r['salary_total'] > 0)
                                <div class="davya-books-roll__row"><span>{{ $amountLabel }}</span><strong>&#8377;{{ number_format($r['salary_total'], 0) }}</strong></div>
                            @endif
                            @if ($r['loan_total'] > 0)
                                <div class="davya-books-roll__row"><span>{{ $loanLabel }}</span><strong>&#8377;{{ number_format($r['loan_total'], 0) }}</strong></div>
                            @endif
                            <div class="davya-books-roll__row"><span>{{ $movementLabel }}</span><strong>&#8377;{{ number_format($movementValue, 0) }}</strong></div>
                            <div class="davya-books-roll__row"><span>{{ $balanceLabel }}</span><strong style="{{ $r['balance_total'] < 0 ? 'color:var(--danger);' : '' }}">&#8377;{{ number_format($r['balance_total'], 0) }}</strong></div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Asset register --}}
    @if (count($assets) && $visibleRegions['assets'])
        <div class="davya-section-card">
            <div class="davya-section-card-title">Asset register</div>
            <div class="davya-table-scroll">
                <table class="davya-books-table">
                    <thead><tr>
                        <th>Asset</th><th class="num">Original</th><th class="num">Dep (This FY)</th>
                        <th class="num">Accumulated</th><th class="num">Book value</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($assets as $a)
                            <tr>
                                <td class="title"><a href="{{ url('/admin/books/'.$companySlug.'/'.$fyLabel.'/section/'.$a['section_slug']) }}">{{ $a['name'] }}</a></td>
                                <td class="num">{{ number_format($a['original'], 2) }}</td>
                                <td class="num">{{ number_format($a['this_year'], 2) }}</td>
                                <td class="num">{{ number_format($a['accumulated'], 2) }}</td>
                                <td class="num">{{ number_format($a['book_value'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Loans outstanding --}}
    @if (count($loans) && $visibleRegions['loans'])
        <div class="davya-section-card">
            <div class="davya-section-card-title">Loans outstanding</div>
            <div class="davya-table-scroll">
                <table class="davya-books-table">
                    <thead><tr>
                        <th>Counterparty</th>
                        <th>Kind</th>
                        <th>Interest</th>
                        <th class="num">Principal</th>
                        <th class="num">Movement</th>
                        <th class="num">Outstanding</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($loans as $l)
                            <tr>
                                <td class="title">
                                    @if ($l['section_slug'])
                                        <a href="{{ url('/admin/books/'.$companySlug.'/'.$fyLabel.'/section/'.$l['section_slug']) }}">{{ $l['title'] }}</a>
                                    @else
                                        {{ $l['title'] }}
                                    @endif
                                </td>
                                <td>
                                    <span class="davya-books-badge davya-books-badge--{{ $l['kind'] === 'taken' ? 'warning' : 'success' }}">
                                        {{ $l['kind'] === 'taken' ? 'Taken' : 'Given' }}
                                    </span>
                                </td>
                                <td style="font-size:var(--fs-12); color:var(--text-sub);">{{ $l['interest_rate'] ?? '—' }}</td>
                                <td class="num">{{ number_format($l['loan'], 2) }}</td>
                                <td class="num">
                                    {{ number_format($l['kind'] === 'taken' ? $l['repaid'] : $l['received_back'], 2) }}
                                    <div style="font-size:var(--fs-10); color:var(--text-muted); font-weight:400;">{{ $l['kind'] === 'taken' ? 'Repaid' : 'Received' }}</div>
                                </td>
                                <td class="num"><strong>{{ number_format($l['outstanding'], 2) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
