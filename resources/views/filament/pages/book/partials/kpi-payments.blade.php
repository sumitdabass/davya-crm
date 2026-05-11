@if ($payments->isEmpty())
    <div style="color:var(--text-sub); text-align:center; padding:32px 0;">No payments contributed to this total yet.</div>
@else
    <div class="davya-table-scroll">
        <table class="davya-books-table">
            <thead><tr>
                <th>Date</th>
                <th>Section / Entry</th>
                <th>Reference</th>
                <th>Mode</th>
                <th class="num">Amount</th>
            </tr></thead>
            <tbody>
                @foreach ($payments as $p)
                    <tr>
                        <td style="white-space:nowrap; color:var(--text-sub);">{{ $p->occurred_on->format('d M Y') }}</td>
                        <td>
                            @if ($p->entry?->section)
                                <a href="{{ url('/admin/books/'.$companySlug.'/'.$fyLabel.'/section/'.$p->entry->section->slug) }}">
                                    {{ $p->entry->section->name }} · {{ $p->entry->title }}
                                </a>
                            @else
                                {{ $p->entry?->title ?? '—' }}
                            @endif
                        </td>
                        <td style="font-size:var(--fs-12); color:var(--text-sub);">{{ $p->reference ?? '—' }}</td>
                        <td>
                            <span class="davya-books-badge">{{ ucfirst($p->mode) }}</span>
                        </td>
                        <td class="num"><strong>&#8377;{{ number_format((float) $p->amount, 2) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Total &mdash; {{ $label }}</td>
                    <td class="num"><strong>&#8377;{{ number_format($total, 2) }}</strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
@endif
