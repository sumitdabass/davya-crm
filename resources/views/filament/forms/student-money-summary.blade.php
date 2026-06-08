@php($r = $getRecord())
@if ($r)
    @php($mf = \App\Support\MoneyFormat::class)
    @php($fmt = fn ($v) => ((float) $v < 0 ? '-₹' : '₹') . $mf::indianShort(abs((float) $v)))
    <div class="text-sm text-gray-500 dark:text-gray-400"
         style="margin-top:4px; line-height:1.5;"
         data-testid="student-money-summary">
        <span>{{ $fmt($r->deal_amount) }} deal</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span>{{ $fmt($r->total_received) }} received</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span @style(['color:var(--warning,#D97706)' => $r->pending_amount > 0])>{{ $fmt($r->pending_amount) }} pending</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span>{{ $fmt($r->total_payouts) }} payouts</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span @style(['color:var(--danger,#DC2626)' => $r->expected_profit < 0])>{{ $fmt($r->expected_profit) }} profit</span>
    </div>
@endif
