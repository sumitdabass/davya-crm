<div style="display:grid; gap:8px;">
    @forelse ($attachments as $a)
        <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 12px; border:1px solid var(--border); border-radius:var(--r-md); background:var(--surface);">
            <div>
                <a href="{{ \Illuminate\Support\Facades\Storage::disk($a->disk)->url($a->path) }}"
                   target="_blank" rel="noopener"
                   style="color:var(--brand-700); text-decoration:none; font-weight:500; font-size:var(--fs-13);"
                   onmouseover="this.style.textDecoration='underline';"
                   onmouseout="this.style.textDecoration='none';">{{ $a->original_name }}</a>
                <div style="font-size:var(--fs-10); color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">
                    {{ $a->mime ?? '—' }} · {{ number_format(($a->size ?? 0) / 1024, 1) }} KB · {{ $a->uploaded_at?->format('d M Y H:i') }}
                </div>
            </div>
            <button type="button"
                wire:click="mountAction('deleteDocument', { id: {{ $a->id }} })"
                wire:confirm="Delete this document? This cannot be undone."
                style="background:transparent; border:0; cursor:pointer; color:var(--danger); font-size:var(--fs-11); padding:4px 8px; border-radius:var(--r-sm);"
                onmouseover="this.style.background='#FEE2E2';"
                onmouseout="this.style.background='transparent';">Delete</button>
        </div>
    @empty
        <div style="color:var(--text-sub); text-align:center; padding:24px 0; font-size:var(--fs-13);">No documents uploaded yet.</div>
    @endforelse
</div>
