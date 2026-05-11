<x-filament-panels::page>
    <div class="mb-4">
        <a href="{{ url('/admin/books/'.$companyModel->slug.'/'.$fyModel->label) }}"
           class="text-sm text-gray-500">&larr; {{ $companyModel->name }} / FY {{ $fyModel->label }}</a>
        <h2 class="text-xl font-semibold">{{ $sectionModel->name }}</h2>
    </div>

    @php
        $cols = $this->getVisibleMoneyColumns();
        $entries = $this->getEntries();
    @endphp

    <table class="w-full text-sm border rounded-lg overflow-hidden">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left p-2 w-12">#</th>
                <th class="text-left p-2">Title</th>
                @if (in_array('salary', $cols)) <th class="text-right p-2">Salary</th>@endif
                @if (in_array('loan', $cols)) <th class="text-right p-2">Loan</th>@endif
                @if (in_array('paid', $cols)) <th class="text-right p-2">Paid</th>@endif
                @if (in_array('received_back', $cols)) <th class="text-right p-2">Received Back</th>@endif
                @if (in_array('balance', $cols)) <th class="text-right p-2">Balance</th>@endif
                @if (in_array('loan_outstanding', $cols)) <th class="text-right p-2">Loan Outstanding</th>@endif
                <th class="text-left p-2">Notes</th>
                <th class="text-right p-2">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $i => $e)
                <tr class="border-t">
                    <td class="p-2">{{ $i + 1 }}</td>
                    <td class="p-2 font-medium">{{ $e->title }}</td>
                    @if (in_array('salary', $cols)) <td class="p-2 text-right">{{ number_format((float) $e->salary_amount, 2) }}</td>@endif
                    @if (in_array('loan', $cols)) <td class="p-2 text-right">{{ number_format((float) $e->loan_amount, 2) }}</td>@endif
                    @if (in_array('paid', $cols)) <td class="p-2 text-right">{{ number_format((float) $e->paid, 2) }}</td>@endif
                    @if (in_array('received_back', $cols)) <td class="p-2 text-right">{{ number_format((float) $e->received_back, 2) }}</td>@endif
                    @if (in_array('balance', $cols)) <td class="p-2 text-right">{{ number_format((float) $e->balance, 2) }}</td>@endif
                    @if (in_array('loan_outstanding', $cols)) <td class="p-2 text-right">{{ number_format((float) $e->loan_outstanding, 2) }}</td>@endif
                    <td class="p-2 text-gray-500">{{ $e->notes }}</td>
                    <td class="p-2 text-right whitespace-nowrap">
                        <button type="button" wire:click="mountAction('editEntry', { id: {{ $e->id }} })"
                                class="text-blue-600 hover:text-blue-800 text-xs">Edit</button>
                        <button type="button" wire:click="mountAction('deleteEntry', { id: {{ $e->id }} })"
                                class="text-red-600 hover:text-red-800 text-xs ml-2">Delete</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="p-4 text-gray-500 text-center">No entries &mdash; click "+ Add Row".</td></tr>
            @endforelse
        </tbody>
    </table>
</x-filament-panels::page>
