<div class="space-y-2">
    @forelse ($attachments as $a)
        <div class="flex items-center justify-between p-2 border rounded">
            <div>
                <a href="{{ \Illuminate\Support\Facades\Storage::disk($a->disk)->url($a->path) }}"
                   target="_blank" rel="noopener"
                   class="text-blue-600 hover:underline">{{ $a->original_name }}</a>
                <div class="text-xs text-gray-500">
                    {{ $a->mime ?? '—' }} · {{ number_format(($a->size ?? 0) / 1024, 1) }} KB
                    · {{ $a->uploaded_at?->format('d M Y H:i') }}
                </div>
            </div>
            <button type="button"
                wire:click="mountAction('deleteDocument', { id: {{ $a->id }} })"
                class="text-red-600 hover:text-red-800 text-xs">Delete</button>
        </div>
    @empty
        <div class="text-gray-500 text-sm">No documents uploaded yet.</div>
    @endforelse
</div>
