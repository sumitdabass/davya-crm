<div x-data style="display:grid; gap:8px;">
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
                            <td class="num"><x-book-amount :v="(float)$p->amount" /></td>
                            <td style="font-size:var(--fs-12); color:var(--text-sub);">{{ $p->source ?? '—' }}</td>
                            <td style="font-size:var(--fs-12); color:var(--text-sub);">{{ $p->reference ?? '—' }}</td>
                            <td style="font-size:var(--fs-11); color:var(--text-muted); white-space:nowrap;"
                                @if ($p->createdBy?->email) title="{{ $p->createdBy->email }}" @endif>
                                {{ $p->createdBy ? \Illuminate\Support\Str::before($p->createdBy->email, '@') : '—' }}
                            </td>
                            <td class="actions">
                                {{-- Use Alpine @click + $wire instead of Livewire.dispatch:
                                     window.Livewire is unreachable from this teleported modalContent
                                     in production (the bridge listener never fires), but Alpine binds
                                     at scan time and $wire references the SectionPage component directly. --}}
                                <button type="button"
                                    x-on:click="$wire.mountAction('editPayment', { id: {{ $p->id }} })"
                                    data-variant="primary">Edit</button>
                                <button type="button"
                                    x-on:click="if (confirm('Delete this payment? This cannot be undone.')) $wire.mountAction('deletePayment', { id: {{ $p->id }} })"
                                    data-variant="danger">Delete</button>
                            </td>
                        </tr>
                        @if (! empty($p->notes))
                            <tr class="davya-books-payment-notes">
                                <td colspan="8" style="padding-top:0; padding-bottom:8px; color:var(--text-sub); font-size:var(--fs-12); font-style:italic; border-top:none;">
                                    <span style="color:var(--text-muted); font-style:normal; font-weight:500; margin-right:4px;">Note:</span>{{ $p->notes }}
                                </td>
                            </tr>
                        @endif
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
                            <x-book-amount :v="$net" :danger="$net < 0" />
                        </td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
