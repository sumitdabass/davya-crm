<x-filament-panels::page>
    <div class="mb-4">
        <a href="{{ url('/admin/books/'.$companyModel->slug.'/'.$fyModel->label) }}"
           class="text-sm text-gray-500">&larr; {{ $companyModel->name }} / FY {{ $fyModel->label }}</a>
        <h2 class="text-xl font-semibold">Income &mdash; {{ $companyModel->name }} / FY {{ $fyModel->label }}</h2>
    </div>

    @php
        $rows = $this->getIncome();
    @endphp

    <table class="w-full text-sm border rounded-lg overflow-hidden">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left p-2">Date</th>
                <th class="text-left p-2">Source</th>
                <th class="text-right p-2">Amount</th>
                <th class="text-left p-2">Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $i)
                <tr class="border-t">
                    <td class="p-2">{{ $i->occurred_on->format('d M Y') }}</td>
                    <td class="p-2 font-medium">{{ $i->source }}</td>
                    <td class="p-2 text-right">{{ number_format((float) $i->amount, 2) }}</td>
                    <td class="p-2 text-gray-500">{{ $i->notes }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-4 text-gray-500 text-center">No income yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</x-filament-panels::page>
