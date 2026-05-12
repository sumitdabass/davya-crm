@if (empty($rows))
    <div style="color:var(--text-sub); text-align:center; padding:32px 0;">No assets in this FY.</div>
@else
    <div class="davya-table-scroll">
        <table class="davya-books-table">
            <thead><tr>
                <th>Asset</th>
                <th>Method</th>
                <th class="num">Original</th>
                <th class="num">Rate</th>
                <th class="num">Dep this FY</th>
            </tr></thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td class="title">
                            @if ($r['section_slug'])
                                <a href="{{ url('/admin/books/'.$companySlug.'/'.$fyLabel.'/section/'.$r['section_slug']) }}">{{ $r['name'] }}</a>
                            @else
                                {{ $r['name'] }}
                            @endif
                        </td>
                        <td>
                            <span class="davya-books-badge">{{ $r['method'] === 'wdv' ? 'WDV' : 'Straight Line' }}</span>
                        </td>
                        <td class="num"><x-book-amount :v="$r['original']" /></td>
                        <td class="num">{{ number_format($r['percent'], 2) }}%</td>
                        <td class="num"><x-book-amount :v="$r['this_year']" /></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Total Non-Cash Outflow</td>
                    <td class="num"><x-book-amount :v="$total" /></td>
                </tr>
            </tfoot>
        </table>
    </div>
@endif
