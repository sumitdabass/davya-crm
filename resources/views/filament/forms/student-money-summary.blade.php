@php($r = $getRecord())
@if ($r)
    @php($mf = \App\Support\MoneyFormat::class)
    @php($fmt = fn ($v) => ((float) $v < 0 ? '-₹' : '₹') . $mf::indianShort(abs((float) $v)))
    <div class="text-sm text-gray-500 dark:text-gray-400" style="margin-top:4px; line-height:1.5;" data-testid="student-money-summary">
        <button type="button" wire:click="mountAction('editDeal')"
                class="hover:underline cursor-pointer" style="display:inline; color:inherit;"
                title="Edit deal amount">{{ $fmt($r->deal_amount) }} deal</button>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <button type="button" wire:click="mountAction('managePayment')"
                class="hover:underline cursor-pointer" style="display:inline; color:inherit;"
                title="Add / update / delete a payment">{{ $fmt($r->total_received) }} received</button>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span @style(['color:var(--warning,#D97706)' => $r->pending_amount > 0])>{{ $fmt($r->pending_amount) }} pending</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <button type="button" wire:click="mountAction('managePayout')"
                class="hover:underline cursor-pointer" style="display:inline; color:inherit;"
                title="Add / update / delete a payout">{{ $fmt($r->total_payouts) }} payouts</button>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span @style(['color:var(--success,#059669)' => $r->payouts_paid > 0])>{{ $fmt($r->payouts_paid) }} paid out</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span @style(['color:var(--warning,#D97706)' => $r->payouts_outstanding > 0])>{{ $fmt($r->payouts_outstanding) }} to pay</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span @style(['color:var(--danger,#DC2626)' => $r->expected_profit < 0])>{{ $fmt($r->expected_profit) }} profit</span>
    </div>
@endif
