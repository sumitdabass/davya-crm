<div>
    @forelse ($activities as $a)
        <div class="davya-card-row" style="flex-direction: column; align-items: stretch;">
            <div style="display: flex; justify-content: space-between; font-size: var(--fs-10); color: var(--text-sub); margin-bottom: 2px;">
                <span>{{ $a->causer?->name ?? 'System' }}</span>
                <span>{{ $a->created_at?->diffForHumans() }}</span>
            </div>
            <div style="font-size: var(--fs-12); color: var(--text);">{{ $a->description }}</div>
        </div>
    @empty
        <p style="color: var(--text-sub); font-size: var(--fs-12); text-align: center; padding: 16px;">No activity yet.</p>
    @endforelse
</div>
