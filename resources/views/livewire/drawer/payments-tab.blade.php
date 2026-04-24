<div>
    @forelse ($payments as $p)
        <div class="davya-card-row">
            <span style="flex: 1; font-weight: 600;">₹{{ number_format($p->amount) }}</span>
            <span style="color: var(--text-sub); font-size: var(--fs-11);">{{ $p->type ?? '' }}</span>
            <span style="color: var(--text-sub); font-size: var(--fs-11);">{{ $p->created_at?->format('d M Y') }}</span>
        </div>
    @empty
        <p style="color: var(--text-sub); font-size: var(--fs-12); text-align: center; padding: 16px;">No payments yet.</p>
    @endforelse
</div>
