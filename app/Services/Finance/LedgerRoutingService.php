<?php
namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\Investment;
use App\Models\Payment;
use App\Models\User;

class LedgerRoutingService
{
    public const DAVYA_ACCOUNT = 'davya';

    /**
     * Compute ledger_entries rows for a Payment. Returns an array of
     * associative arrays shaped ['account','delta_amount','source_type','source_id','note'].
     * Does NOT persist — the controller writes them inside a transaction.
     *
     * @return array<int, array<string, mixed>>
     */
    public function routePayment(Payment $payment): array
    {
        $referrer = $payment->student->referrer;

        if ($referrer === null) {
            // Walk-in or unresolved — safest behaviour: full amount to Davya.
            return [$this->row(self::DAVYA_ACCOUNT, $payment->amount, 'payment', $payment->id, 'no referrer')];
        }

        if ((bool) $referrer->is_freelancer) {
            return [$this->row(self::DAVYA_ACCOUNT, $payment->amount, 'payment', $payment->id, 'freelancer referral')];
        }

        $head = $referrer->team_head_id !== null
            ? User::find($referrer->team_head_id)
            : $referrer;

        if ($head === null || (int) $head->split_pct === 0) {
            $whoseSplit = $head ? $head->name : 'unknown';
            return [$this->row(self::DAVYA_ACCOUNT, $payment->amount, 'payment', $payment->id, "head {$whoseSplit} has 0% split")];
        }

        $headShare  = round(((float) $payment->amount) * ((int) $head->split_pct) / 100, 2);
        $davyaShare = round(((float) $payment->amount) - $headShare, 2);

        return [
            $this->row(strtolower($head->name), $headShare,  'payment', $payment->id, "head share {$head->split_pct}%"),
            $this->row(self::DAVYA_ACCOUNT,     $davyaShare, 'payment', $payment->id, 'davya share'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function routeExpense(Expense $expense): array
    {
        $note = 'expense'.($expense->category ? ": {$expense->category}" : '');
        return [$this->row(self::DAVYA_ACCOUNT, -$expense->amount, 'expense', $expense->id, $note)];
    }

    /** @return array<int, array<string, mixed>> */
    public function routeInvestment(Investment $inv): array
    {
        $sign = $inv->direction === 'in' ? 1 : -1;
        $delta = $sign * (float) $inv->amount;
        $note = "investment {$inv->direction}: {$inv->asset_name}";
        return [$this->row(self::DAVYA_ACCOUNT, $delta, 'investment', $inv->id, $note)];
    }

    /** @return array<string, mixed> */
    private function row(string $account, float|string $delta, string $sourceType, int $sourceId, string $note): array
    {
        return [
            'account'      => $account,
            'delta_amount' => number_format((float) $delta, 2, '.', ''),
            'source_type'  => $sourceType,
            'source_id'    => $sourceId,
            'note'         => $note,
        ];
    }
}
