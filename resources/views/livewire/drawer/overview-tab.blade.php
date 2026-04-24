<div>
    <div class="davya-section-card">
        <div class="davya-section-card-title">Deal</div>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Deal amount</span><span>₹{{ number_format($deal) }}</span></div>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Received</span><span>₹{{ number_format($received) }}</span></div>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Pending</span><span style="color: #B45309; font-weight: 700;">₹{{ number_format($pending) }}</span></div>
        <div style="height: 5px; background: var(--border); border-radius: 3px; overflow: hidden; margin: 6px 0 4px;">
            <div style="height: 100%; background: var(--success); width: {{ $pct }}%;"></div>
        </div>
        <div style="font-size: var(--fs-10); color: var(--text-sub);">{{ $pct }}% paid</div>
    </div>

    @if ($s->course || $s->current_round || $s->lead_source)
        <div class="davya-section-card">
            <div class="davya-section-card-title">Context</div>
            @if ($s->course)
                <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Course</span><span>{{ $s->course }}</span></div>
            @endif
            @if ($s->current_round)
                <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Round</span><span>{{ $s->current_round }}</span></div>
            @endif
            @if ($s->lead_source)
                <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Source</span><span>{{ $s->lead_source }}</span></div>
            @endif
        </div>
    @endif
</div>
