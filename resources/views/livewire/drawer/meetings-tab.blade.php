<div>
    @forelse ($meetings as $m)
        <div class="davya-card-row">
            <span style="flex: 1; font-weight: 600;">{{ $m->scheduled_at?->format('d M, h:i A') ?? '—' }}</span>
            <span style="color: var(--text-sub); font-size: var(--fs-11);">{{ $m->kind ?? 'Meeting' }}</span>
            <span style="color: var(--text-sub); font-size: var(--fs-11);">{{ $m->status ?? '—' }}</span>
        </div>
    @empty
        <p style="color: var(--text-sub); font-size: var(--fs-12); text-align: center; padding: 16px;">No meetings yet.</p>
    @endforelse
</div>
