<x-filament-panels::page>
    @if ($step === 'input')
        <div class="space-y-6">
            <div>
                <label class="font-semibold">Source</label>
                <div class="mt-2 flex gap-4">
                    @foreach (['sonam' => 'Sonam', 'nikhil' => 'Nikhil', 'sumit-website' => 'Sumit — Website', 'canonical' => 'Other (canonical)'] as $val => $label)
                        <label class="flex items-center gap-2">
                            <input type="radio" wire:model.live="source" value="{{ $val }}">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="mt-2 text-sm">
                    <a class="text-primary-600 underline" href="{{ asset('templates/lead-import-'.$source.'.csv') }}" download>Download {{ $source }} template</a>
                </div>
            </div>

            <div>
                <label class="font-semibold">Paste TSV from Google Sheets</label>
                <textarea wire:model="paste" rows="10" class="mt-2 w-full rounded border p-2 font-mono text-xs"></textarea>
            </div>

            <div>
                <label class="font-semibold">Or upload CSV / XLSX</label>
                <input type="file" wire:model="upload" accept=".csv,.tsv,.xlsx" class="mt-2 block">
            </div>

            @if ($parseError)
                <div class="rounded bg-danger-50 p-3 text-danger-700">{{ $parseError }}</div>
            @endif

            <x-filament::button wire:click="runPreview">Preview</x-filament::button>
        </div>
    @elseif ($step === 'preview')
        <div class="space-y-6">
            <div class="grid grid-cols-4 gap-4">
                <div class="rounded bg-success-50 p-3"><div class="text-xs">Create</div><div class="text-2xl">{{ $previewCreateCount }}</div></div>
                <div class="rounded bg-warning-50 p-3"><div class="text-xs">Merge</div><div class="text-2xl">{{ $previewMergeCount }}</div></div>
                <div class="rounded bg-primary-50 p-3"><div class="text-xs">Flag</div><div class="text-2xl">{{ $previewFlagCount }}</div></div>
                <div class="rounded bg-danger-50 p-3"><div class="text-xs">Reject</div><div class="text-2xl">{{ $previewRejectCount }}</div></div>
            </div>

            <details open>
                <summary class="cursor-pointer font-semibold">Rows ({{ count($previewRows) }})</summary>
                <table class="mt-3 w-full text-sm">
                    <thead><tr class="border-b"><th class="p-1 text-left">#</th><th class="p-1 text-left">Action</th><th class="p-1 text-left">Phone</th><th class="p-1 text-left">Course</th><th class="p-1 text-left">Name</th><th class="p-1 text-left">Reason</th></tr></thead>
                    <tbody>
                    @foreach (array_slice($previewRows, 0, 200) as $row)
                        <tr class="border-b">
                            <td class="p-1">{{ $row['row_number'] }}</td>
                            <td class="p-1 font-mono">{{ $row['action'] }}</td>
                            <td class="p-1">{{ $row['phone'] }}</td>
                            <td class="p-1">{{ $row['course'] }}</td>
                            <td class="p-1">{{ $row['name'] }}</td>
                            <td class="p-1 text-xs text-gray-500">{{ $row['reason'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if (count($previewRows) > 200)
                    <div class="mt-2 text-xs text-gray-500">Showing first 200 of {{ count($previewRows) }} rows.</div>
                @endif
            </details>

            <div class="flex gap-2">
                <x-filament::button wire:click="commitPreview">Confirm import</x-filament::button>
                <x-filament::button color="gray" wire:click="backToInput">Back</x-filament::button>
            </div>
        </div>
    @elseif ($step === 'done')
        <div class="space-y-6">
            <div class="rounded bg-success-50 p-4">
                <div class="text-lg font-semibold">Batch #{{ $committedBatchId }} committed</div>
                <ul class="mt-2 text-sm">
                    <li>Created: {{ $committedCreateCount }}</li>
                    <li>Merged: {{ $committedMergeCount }}</li>
                    <li>Flagged: {{ $committedFlagCount }}</li>
                    <li>Rejected: {{ $committedRejectCount }}</li>
                </ul>
            </div>
            @if ($rejectionsUrl)
                <a class="text-primary-600 underline" href="{{ $rejectionsUrl }}" download>Download rejections CSV</a>
                <div class="text-xs text-gray-500">This link is one-shot — the CSV is deleted after you download it.</div>
            @endif
            <x-filament::button wire:click="resetForm">Import another batch</x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
