@php($r = $getRecord())
@if ($r)
    @php($mf = \App\Support\MoneyFormat::class)
    <div class="mt-3 grid grid-cols-2 md:grid-cols-5 gap-2" data-testid="student-money-summary">
        <div class="davya-books-kpi">
            <div class="davya-books-kpi__label">Deal</div>
            {!! $mf::asInlineHtml((float) $r->deal_amount) !!}
        </div>
        <div class="davya-books-kpi">
            <div class="davya-books-kpi__label">Received</div>
            {!! $mf::asInlineHtml((float) $r->total_received) !!}
        </div>
        <div class="davya-books-kpi">
            <div class="davya-books-kpi__label">Pending</div>
            {!! $mf::asInlineHtml((float) $r->pending_amount, $r->pending_amount > 0) !!}
        </div>
        <div class="davya-books-kpi">
            <div class="davya-books-kpi__label">Payouts</div>
            {!! $mf::asInlineHtml((float) $r->total_payouts) !!}
        </div>
        <div class="davya-books-kpi">
            <div class="davya-books-kpi__label">Expected profit</div>
            {!! $mf::asInlineHtml((float) $r->expected_profit, $r->expected_profit < 0) !!}
        </div>
    </div>
@endif
