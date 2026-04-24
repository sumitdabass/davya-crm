<div>
    @foreach ($notes as $n)
        <div class="davya-card-row" style="flex-direction: column; align-items: stretch;">
            <div style="display: flex; justify-content: space-between; font-size: var(--fs-10); color: var(--text-sub); margin-bottom: 4px;">
                <span>{{ $n->author?->name ?? '—' }}</span>
                <span>{{ $n->created_at?->diffForHumans() }}</span>
            </div>
            <div style="font-size: var(--fs-12); color: var(--text);">{{ $n->body }}</div>
        </div>
    @endforeach
    <div style="margin-top: 10px;">
        <textarea wire:model="body" placeholder="Add a note…" style="width: 100%; min-height: 60px; padding: 8px; border: 1px solid var(--border); border-radius: var(--r-md); font-size: var(--fs-12); font-family: inherit; resize: vertical;"></textarea>
        <button wire:click="save" style="margin-top: 6px; background: var(--brand-600); color: white; border: 0; border-radius: var(--r-md); padding: 6px 12px; font-size: var(--fs-11); font-weight: 600; cursor: pointer;">Add note</button>
    </div>
</div>
