<div style="display:grid; gap:8px;">
    @if ($payments->isEmpty())
        <div style="color:var(--text-sub); text-align:center; padding:24px 0; font-size:var(--fs-13);">No payments recorded yet.</div>
    @else
        <div class="davya-table-scroll">
            <table class="davya-books-table">
                <thead><tr>
                    <th>Date</th>
                    <th>Direction</th>
                    <th>Mode</th>
                    <th class="num">Amount</th>
                    <th>Source</th>
                    <th>Reference</th>
                    <th>By</th>
                    <th class="actions"></th>
                </tr></thead>
                <tbody>
                    @foreach ($payments as $p)
                        <tr>
                            <td style="white-space:nowrap; color:var(--text-sub);">{{ $p->occurred_on->format('d M Y') }}</td>
                            <td>
                                <span class="davya-books-badge davya-books-badge--{{ $p->direction === 'in' ? 'success' : 'warning' }}">
                                    {{ $p->direction === 'in' ? 'Received' : 'Paid out' }}
                                </span>
                            </td>
                            <td>
                                <span class="davya-books-badge">{{ ucfirst($p->mode) }}</span>
                            </td>
                            <td class="num"><strong>&#8377; {{ number_format((float) $p->amount, 2) }}</strong></td>
                            <td style="font-size:var(--fs-12); color:var(--text-sub);">{{ $p->source ?? '—' }}</td>
                            <td style="font-size:var(--fs-12); color:var(--text-sub);">{{ $p->reference ?? '—' }}</td>
                            <td style="font-size:var(--fs-11); color:var(--text-muted);">{{ $p->createdBy?->email ?? '—' }}</td>
                            <td class="actions">
                                <button type="button" wire:click="mountAction('editPayment', { id: {{ $p->id }} })" data-variant="primary">Edit</button>
                                <button type="button"
                                    onclick="if (confirm('Delete this payment? This cannot be undone.')) { $wire.mountAction('deletePayment', { id: {{ $p->id }} }); }"
                                    data-variant="danger">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">Net (in &minus; out)</td>
                        <td class="num">
                            @php
                                $inSum = $payments->where('direction', 'in')->sum('amount');
                                $outSum = $payments->where('direction', 'out')->sum('amount');
                                $net = $inSum - $outSum;
                            @endphp
                            <strong style="color:{{ $net >= 0 ? 'var(--brand-700)' : 'var(--danger)' }};">
                                &#8377; {{ number_format($net, 2) }}
                            </strong>
                        </td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
