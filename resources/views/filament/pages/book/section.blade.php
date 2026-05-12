<x-filament-panels::page>
    @php
        $cols = $this->getVisibleMoneyColumns();
        $entries = $this->getEntries();
        $isLoanSection = in_array($sectionModel->slug, ['loan', 'loans_taken'], true);
        $loanLabel = $sectionModel->slug === 'loans_taken' ? 'Principal taken' : 'Principal lent';
        $isAssetSection = $sectionModel->kind === 'asset';
        $assetCalc = $isAssetSection ? new \App\Books\Services\DepreciationCalculator() : null;
        $assetsByEntry = $isAssetSection
            ? \App\Models\Book\Asset::whereIn('entry_id', $entries->pluck('id'))->get()->keyBy('entry_id')
            : collect();
        $colsCount = 4
            + (in_array('salary',$cols)?1:0) + (in_array('loan',$cols)?1:0)
            + (in_array('paid',$cols)?1:0) + (in_array('received_back',$cols)?1:0)
            + (in_array('repaid',$cols)?1:0) + (in_array('balance',$cols)?1:0)
            + (in_array('loan_outstanding',$cols)?1:0) + (in_array('loan_outstanding_taken',$cols)?1:0)
            + ($isAssetSection ? 4 : 0);
    @endphp

    <x-book-crumbs :company="$companyModel" :fy="$fyModel" :title="$sectionModel->name" />

    <div class="davya-section-card">
        <div class="davya-section-card-title">{{ count($entries) }} {{ count($entries) === 1 ? 'entry' : 'entries' }}</div>
        <div class="davya-table-scroll">
            <table class="davya-books-table">
                <thead><tr>
                    <th style="width:32px;">#</th>
                    <th>Title</th>
                    @if (in_array('salary',$cols))<th class="num">{{ $sectionModel->periodicAmountLabel() }}</th>@endif
                    @if (in_array('loan',$cols))<th class="num">{{ $loanLabel }}</th>@endif
                    @if ($isLoanSection)<th>Interest</th>@endif
                    @if ($isLoanSection)<th>EMI · Tenure</th>@endif
                    @if (in_array('paid',$cols))<th class="num">Paid</th>@endif
                    @if (in_array('received_back',$cols))<th class="num">Received back</th>@endif
                    @if (in_array('repaid',$cols))<th class="num">Repaid</th>@endif
                    @if (in_array('balance',$cols))<th class="num">Balance</th>@endif
                    @if (in_array('loan_outstanding',$cols))<th class="num">Outstanding (owed to us)</th>@endif
                    @if (in_array('loan_outstanding_taken',$cols))<th class="num">Outstanding (we owe)</th>@endif
                    @if ($isAssetSection)
                        <th class="num">Original</th>
                        <th>% · Method · Life</th>
                        <th class="num">This year dep</th>
                        <th class="num">Book value</th>
                    @endif
                    <th>Frequency</th>
                    <th>Payments</th>
                    <th>Docs</th>
                    <th>Notes</th>
                    <th class="actions">Actions</th>
                </tr></thead>
                <tbody>
                    @forelse ($entries as $i => $e)
                        <tr>
                            <td style="color:var(--text-muted);">{{ $i + 1 }}</td>
                            <td class="title">{{ $e->title }}</td>
                            @if (in_array('salary',$cols))
                                <td class="num">
                                    <x-book-amount :v="(float)$e->salary_amount" />
                                    @if ($e->frequency !== 'one_time')
                                        <div style="font-size:var(--fs-10); color:var(--text-muted); font-weight:400; margin-top:4px;">{{ \App\Models\Book\Entry::FREQUENCIES[$e->frequency] }} &middot; ann &#8377;{{ number_format($e->annualized_salary_amount, 0) }}</div>
                                    @endif
                                </td>
                            @endif
                            @if (in_array('loan',$cols))<td class="num"><x-book-amount :v="(float)$e->loan_amount" /></td>@endif
                            @if ($isLoanSection)<td style="font-size:var(--fs-12); color:var(--text-sub);">{{ $e->interest_rate ?? '—' }}</td>@endif
                            @if ($isLoanSection)
                                <td style="font-size:var(--fs-12); color:var(--text-sub); white-space:nowrap;">
                                    @php
                                        $hasEmi = (float)($e->emi_amount ?? 0) > 0;
                                        $hasTenure = (int)($e->tenure_months ?? 0) > 0;
                                    @endphp
                                    @if ($hasEmi || $hasTenure)
                                        @if ($hasEmi)&#8377;{{ number_format((float)$e->emi_amount, 0) }}@else&mdash;@endif
                                        @if ($hasTenure) &middot; {{ $e->tenure_months }} mo @endif
                                    @else
                                        &mdash;
                                    @endif
                                </td>
                            @endif
                            @if (in_array('paid',$cols))<td class="num"><x-book-amount :v="(float)$e->paid" /></td>@endif
                            @if (in_array('received_back',$cols))<td class="num"><x-book-amount :v="(float)$e->received_back" /></td>@endif
                            @if (in_array('repaid',$cols))<td class="num"><x-book-amount :v="(float)$e->repaid" /></td>@endif
                            @if (in_array('balance',$cols))<td class="num"><x-book-amount :v="(float)$e->balance" /></td>@endif
                            @if (in_array('loan_outstanding',$cols))<td class="num"><x-book-amount :v="(float)$e->loan_outstanding" /></td>@endif
                            @if (in_array('loan_outstanding_taken',$cols))<td class="num"><x-book-amount :v="(float)$e->loan_outstanding_taken" /></td>@endif
                            @if ($isAssetSection)
                                @php
                                    $a = $assetsByEntry[$e->id] ?? null;
                                @endphp
                                @if ($a)
                                    <td class="num"><x-book-amount :v="(float)$a->original_value" /></td>
                                    <td style="font-size:var(--fs-11); color:var(--text-sub); white-space:nowrap;">
                                        {{ rtrim(rtrim(number_format((float)$a->dep_percent,2),'0'),'.') }}% · {{ $a->method === 'wdv' ? 'WDV' : 'SL' }} · {{ $a->dep_years }}y
                                    </td>
                                    <td class="num"><x-book-amount :v="(float)$assetCalc->yearlyDepFor($a, $fyModel)" /></td>
                                    <td class="num"><x-book-amount :v="(float)$assetCalc->bookValueAtEndOf($a, $fyModel)" /></td>
                                @else
                                    <td colspan="4" style="color:var(--danger); font-size:var(--fs-11); text-align:center;">No asset record &mdash; click Edit to set depreciation.</td>
                                @endif
                            @endif
                            <td>
                                <span class="davya-books-badge {{ $e->frequency === 'one_time' ? '' : 'davya-books-badge--brand' }}">{{ \App\Models\Book\Entry::FREQUENCIES[$e->frequency] ?? $e->frequency }}</span>
                            </td>
                            <td>
                                @php
                                    $pCount = $e->payments()->count();
                                @endphp
                                <button type="button" wire:click="mountAction('addPayment', { id: {{ $e->id }} })" class="davya-books-action">+ Add</button>
                                @if ($pCount > 0)
                                    <button type="button" wire:click="mountAction('viewPayments', { id: {{ $e->id }} })" class="davya-books-action davya-books-action--pill">{{ $pCount }} payment{{ $pCount === 1 ? '' : 's' }}</button>
                                @endif
                            </td>
                            <td>
                                @php
                                    $count = $e->attachments()->count();
                                @endphp
                                <button type="button" wire:click="mountAction('uploadDocuments', { id: {{ $e->id }} })" class="davya-books-action">+ Add</button>
                                @if ($count > 0)
                                    <button type="button" wire:click="mountAction('viewDocuments', { id: {{ $e->id }} })" class="davya-books-action davya-books-action--pill">{{ $count }} doc{{ $count === 1 ? '' : 's' }}</button>
                                @endif
                            </td>
                            <td style="color:var(--text-sub); font-size:var(--fs-12); max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ $e->notes }}">{{ $e->notes }}</td>
                            <td class="actions">
                                <button type="button" wire:click="mountAction('editEntry', { id: {{ $e->id }} })" data-variant="primary">Edit</button>
                                <button type="button" wire:click="mountAction('reclassifyAsLoan', { id: {{ $e->id }} })" data-variant="warning">Convert to Loan</button>
                                <button type="button" wire:click="mountAction('deleteEntry', { id: {{ $e->id }} })" data-variant="danger">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $colsCount + 6 + ($isLoanSection ? 2 : 0) }}" style="text-align:center; padding:32px 0; color:var(--text-sub);">No entries — click <strong>+ Add Row</strong> above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
