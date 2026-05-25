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
        @php $companyFiscalYears = $this->companyFiscalYears(); @endphp
        @if ($companyFiscalYears->count() > 1)
            <label class="davya-owner-pill" for="davya-fy-switcher"
                   style="background:var(--brand-50); border-color:var(--brand-100); color:var(--brand-700); padding:0; display:inline-flex; align-items:center; cursor:pointer; overflow:hidden;">
                <span style="padding:4px 0 4px 10px;">FY</span>
                <select id="davya-fy-switcher"
                        onchange="if(this.value && this.value !== window.location.pathname) window.location.href=this.value"
                        aria-label="Switch fiscal year"
                        style="appearance:none; -webkit-appearance:none; background:transparent; border:none; color:inherit; font:inherit; padding:4px 22px 4px 6px; cursor:pointer;
                               background-image:url('data:image/svg+xml;utf8,<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;10&quot; height=&quot;6&quot; viewBox=&quot;0 0 10 6&quot;><path fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;1.5&quot; d=&quot;M1 1l4 4 4-4&quot;/></svg>');
                               background-repeat:no-repeat; background-position:right 6px center;">
                    @foreach ($companyFiscalYears as $f)
                        <option value="{{ url('/admin/books/'.$company->slug.'/'.$f->label) }}"
                                @selected($f->id === $fy->id)>
                            {{ $f->label }}{{ $f->is_closed ? ' · closed' : '' }}
                        </option>
                    @endforeach
                </select>
            </label>
        @else
            <span class="davya-owner-pill" style="background:var(--brand-50); border-color:var(--brand-100); color:var(--brand-700);">FY {{ $fyLabel }}{{ $fy->is_closed ? ' · closed' : '' }}</span>
        @endif
    </div>

    {{-- Balance Available — quick "how much do I have right now" snapshot.
         Income + Loans Taken (principal received) − Expense (total outflow). --}}
    @if ($visibleRegions['kpis'])
        @php $balance = $kpis['balance_available']; @endphp
        <div class="davya-section-card" style="margin-bottom:12px;">
            <div class="davya-section-card-title">Balance Available</div>
            <div style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:var(--fs-13); color:var(--text-sub);">
                    <span><span style="color:var(--text-muted); margin-right:6px;">Income</span><x-book-amount :v="$kpis['total_income']" /></span>
                    <span style="color:var(--text-muted);">+</span>
                    <span><span style="color:var(--text-muted); margin-right:6px;">Loans Taken</span><x-book-amount :v="$kpis['loan_taken_principal']" /></span>
                    <span style="color:var(--text-muted);">−</span>
                    <span><span style="color:var(--text-muted); margin-right:6px;">Expense</span><x-book-amount :v="$kpis['total_outflow']" /></span>
                    <span style="color:var(--text-muted);">=</span>
                </div>
                <x-book-amount :v="$balance" big :danger="$balance < 0" />
            </div>
        </div>
    @endif

    {{-- KPI tiles --}}
    @if ($visibleRegions['kpis'])
        <div class="davya-section-card">
            <div class="davya-section-card-title">Year at a glance</div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px;">
                @php
                    // 'kind' drives the colored top border (mirrors .davya-books-roll[data-kind]).
                    $tiles = [
                        ['key'=>'total_income',     'kind'=>'income',     'label'=>'Total Income',     'href'=>url("/admin/books/{$companySlug}/{$fyLabel}/income"), 'hint'=>'View income'],
                        ['key'=>'cash_received',    'kind'=>'income',     'label'=>'Cash Received',    'href'=>null, 'tooltip'=>'Sum of received-back / inbound payments (info-only — does not change Net P/L)'],
                        ['key'=>'salary_paid',             'kind'=>'salary',     'label'=>'Salary Paid',   'href'=>url("/admin/books/{$companySlug}/{$fyLabel}/section/salary"),       'hint'=>'Total paid this FY'],
                        ['key'=>'loans_given_outstanding', 'kind'=>'loan',       'label'=>'Loans Given',   'href'=>url("/admin/books/{$companySlug}/{$fyLabel}/section/loan"),         'hint'=>'Outstanding · owed to us'],
                        ['key'=>'loans_taken_outstanding', 'kind'=>'loans_taken','label'=>'Loans Taken',   'href'=>url("/admin/books/{$companySlug}/{$fyLabel}/section/loans_taken"), 'hint'=>'Outstanding · we owe'],
                        ['key'=>'cash_outflow',     'kind'=>'expense',    'label'=>'Cash Outflow',     'href'=>$defaultGenericSlug ? url("/admin/books/{$companySlug}/{$fyLabel}/section/{$defaultGenericSlug}") : null, 'hint'=>$defaultGenericSlug ? 'View spend' : null],
                        ['key'=>'non_cash_outflow', 'kind'=>'asset',      'label'=>'Non-Cash (Dep)',   'href'=>$assetSlug ? url("/admin/books/{$companySlug}/{$fyLabel}/section/{$assetSlug}") : null, 'hint'=>$assetSlug ? 'View assets' : null],
                        ['key'=>'total_outflow',    'kind'=>'expense',    'label'=>'Total Outflow',    'href'=>$defaultGenericSlug ? url("/admin/books/{$companySlug}/{$fyLabel}/section/{$defaultGenericSlug}") : null, 'hint'=>null],
                        ['key'=>'net_pl',           'kind'=>'summary',    'label'=>'Net P/L',          'href'=>null, 'tooltip'=>'Total Income − Total Outflow (recoveries shown separately as Cash Received)'],
                        ['key'=>'cumulative_pl',    'kind'=>'summary',    'label'=>'Cumulative P/L',   'href'=>null, 'tooltip'=>'Net P/L + Carryover from prior FY'],
                    ];
                @endphp
                @php $kpiMeta = $this->getKpiMeta(); @endphp
                @foreach ($tiles as $tile)
                    @php
                        $value = $kpis[$tile['key']];
                        $meta  = $kpiMeta[$tile['key']] ?? null;
                        $delta = $meta['delta_pct'] ?? null;
                        $sparkSeries = $meta['series'] ?? [];
                        $sparkColor = $value < 0
                            ? 'var(--danger, #EF4444)'
                            : ($delta !== null && $delta < 0 ? 'var(--warning, #F59E0B)' : 'var(--brand-600, #059669)');
                    @endphp
                    @if ($tile['href'])
                        <a href="{{ $tile['href'] }}" class="davya-books-kpi" data-kind="{{ $tile['kind'] }}">
                            <div class="davya-books-kpi__label">{{ $tile['label'] }}</div>
                            <x-book-amount :v="$value" big :danger="$value < 0" />
                            <x-book-kpi-meta :delta="$delta" :prior-label="$meta['prior_label'] ?? null" :series="$sparkSeries" :color="$sparkColor" />
                            @if (! empty($tile['hint']))
                                <div class="davya-books-kpi__hint">{{ $tile['hint'] }}</div>
                            @endif
                        </a>
                    @else
                        <a class="davya-books-kpi" data-kind="{{ $tile['kind'] }}" style="cursor:pointer;"
                           wire:click.prevent="mountAction('explainKpi', { key: '{{ $tile['key'] }}', label: '{{ $tile['label'] }}' })"
                           title="{{ $tile['tooltip'] ?? 'Click to see the math' }}">
                            <div class="davya-books-kpi__label">{{ $tile['label'] }}</div>
                            <x-book-amount :v="$value" big :danger="$value < 0" />
                            <x-book-kpi-meta :delta="$delta" :prior-label="$meta['prior_label'] ?? null" :series="$sparkSeries" :color="$sparkColor" />
                            <div class="davya-books-kpi__hint">See math</div>
                        </a>
                    @endif
                @endforeach

                {{-- Carryover tile --}}
                @php
                    $carryHref = $priorFy ? url("/admin/books/{$companySlug}/{$priorFy}") : null;
                @endphp
                @if ($carryHref)
                    <a href="{{ $carryHref }}" class="davya-books-kpi" data-kind="summary">
                        <div class="davya-books-kpi__label">
                            Carryover
                            @if ($kpis['carryover']['estimate'])
                                <span class="davya-books-badge davya-books-badge--warning">estimate</span>
                            @endif
                        </div>
                        <x-book-amount :v="$kpis['carryover']['value']" big />
                        <div class="davya-books-kpi__hint">View prior FY</div>
                    </a>
                @else
                    <a class="davya-books-kpi" data-kind="summary" style="cursor:pointer;"
                       wire:click.prevent="mountAction('explainKpi', { key: 'carryover', label: 'Carryover' })">
                        <div class="davya-books-kpi__label">
                            Carryover
                            @if ($kpis['carryover']['estimate'])
                                <span class="davya-books-badge davya-books-badge--warning">estimate</span>
                            @endif
                        </div>
                        <x-book-amount :v="$kpis['carryover']['value']" big />
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
                                <div class="davya-books-roll__row"><span>{{ $amountLabel }}</span><x-book-amount :v="$r['salary_total']" /></div>
                            @endif
                            @if ($r['loan_total'] > 0)
                                <div class="davya-books-roll__row"><span>{{ $loanLabel }}</span><x-book-amount :v="$r['loan_total']" /></div>
                            @endif
                            <div class="davya-books-roll__row"><span>{{ $movementLabel }}</span><x-book-amount :v="$movementValue" /></div>
                            <div class="davya-books-roll__row"><span>{{ $balanceLabel }}</span><x-book-amount :v="$r['balance_total']" /></div>
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
                                <td class="num"><x-book-amount :v="$a['original']" /></td>
                                <td class="num"><x-book-amount :v="$a['this_year']" /></td>
                                <td class="num"><x-book-amount :v="$a['accumulated']" /></td>
                                <td class="num"><x-book-amount :v="$a['book_value']" /></td>
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
                                <td class="num"><x-book-amount :v="$l['loan']" /></td>
                                <td class="num">
                                    <x-book-amount :v="$l['kind'] === 'taken' ? $l['repaid'] : $l['received_back']" />
                                    <div style="font-size:var(--fs-10); color:var(--text-muted); font-weight:400; margin-top:4px;">{{ $l['kind'] === 'taken' ? 'Repaid' : 'Received' }}</div>
                                </td>
                                <td class="num"><x-book-amount :v="$l['outstanding']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
