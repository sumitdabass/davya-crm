<x-filament-panels::page>
    @php
        $items = $this->getIncome();
    @endphp

    <x-book-crumbs :company="$companyModel" :fy="$fyModel" title="Income" />

    <div class="davya-section-card">
        <div class="davya-section-card-title">{{ count($items) }} {{ count($items) === 1 ? 'entry' : 'entries' }}</div>
        <div class="davya-table-scroll">
            <table class="davya-books-table">
                <thead><tr>
                    <th>Date</th><th>Source</th><th class="num">Amount</th><th>Notes</th><th class="actions">Actions</th>
                </tr></thead>
                <tbody>
                    @forelse ($items as $i)
                        <tr>
                            <td style="white-space:nowrap; color:var(--text-sub);">{{ $i->occurred_on->format('d M Y') }}</td>
                            <td class="title">{{ $i->source }}</td>
                            <td class="num"><x-book-amount :v="(float) $i->amount" /></td>
                            <td style="color:var(--text-sub); font-size:var(--fs-12);">{{ $i->notes }}</td>
                            <td class="actions">
                                <button type="button" wire:click="mountAction('editIncome', { id: {{ $i->id }} })" data-variant="primary">Edit</button>
                                <button type="button" wire:click="mountAction('deleteIncome', { id: {{ $i->id }} })" data-variant="danger">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="text-align:center; padding:32px 0; color:var(--text-sub);">No income yet — click <strong>+ Add Income</strong> above.</td></tr>
                    @endforelse
                </tbody>
                @if (count($items))
                    <tfoot>
                        <tr>
                            <td colspan="2">Total</td>
                            <td class="num"><x-book-amount :v="$items->sum(fn ($i) => (float) $i->amount)" big inline /></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</x-filament-panels::page>
