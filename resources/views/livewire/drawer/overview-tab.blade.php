<div>
    <div class="davya-section-card">
        <div class="davya-section-card-title">Deal</div>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Deal amount</span><span>₹{{ number_format($deal) }}</span></div>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Received</span><span>₹{{ number_format($received) }}</span></div>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Pending</span><span style="color: var(--warning); font-weight: 700;">₹{{ number_format($pending) }}</span></div>
        <div style="height: 5px; background: var(--border); border-radius: 3px; overflow: hidden; margin: 6px 0 4px;">
            <div style="height: 100%; background: var(--success); width: {{ $pct }}%;"></div>
        </div>
        <div style="font-size: var(--fs-10); color: var(--text-sub);">{{ $pct }}% paid</div>
    </div>

    @php
        $rowStyle = 'display: grid; grid-template-columns: 110px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;';
        $labelStyle = 'color: var(--text-sub);';
        $emptyStyle = 'color: var(--text-sub);';
    @endphp
    <div class="davya-section-card">
        <div class="davya-section-card-title">Context</div>
        <div style="{{ $rowStyle }}"><span style="{{ $labelStyle }}">Course</span><span>{{ $s->course ?: '—' }}</span></div>
        @php
            $choice1 = $s->preference_r1_college
                ? trim($s->preference_r1_college.($s->preference_r1_branch ? ' — '.$s->preference_r1_branch : ''))
                : ($s->preference_r1 ?: null);
            $choice2 = $s->preference_r2_college
                ? trim($s->preference_r2_college.($s->preference_r2_branch ? ' — '.$s->preference_r2_branch : ''))
                : ($s->preference_r2 ?: null);
            $choice3 = $s->preference_r3_college
                ? trim($s->preference_r3_college.($s->preference_r3_branch ? ' — '.$s->preference_r3_branch : ''))
                : ($s->preference_r3 ?: null);
        @endphp
        <div style="{{ $rowStyle }}"><span style="{{ $labelStyle }}">Choice 1</span><span @if(! $choice1) style="{{ $emptyStyle }}" @endif>{{ $choice1 ?: '—' }}</span></div>
        <div style="{{ $rowStyle }}"><span style="{{ $labelStyle }}">Choice 2</span><span @if(! $choice2) style="{{ $emptyStyle }}" @endif>{{ $choice2 ?: '—' }}</span></div>
        <div style="{{ $rowStyle }}"><span style="{{ $labelStyle }}">Choice 3</span><span @if(! $choice3) style="{{ $emptyStyle }}" @endif>{{ $choice3 ?: '—' }}</span></div>
        <div style="{{ $rowStyle }}"><span style="{{ $labelStyle }}">Round</span><span @if(! $s->current_round) style="{{ $emptyStyle }}" @endif>{{ $s->current_round ?: '—' }}</span></div>
        <div style="{{ $rowStyle }}"><span style="{{ $labelStyle }}">Rank</span><span @if($s->rank === null || $s->rank === '') style="{{ $emptyStyle }}" @endif>{{ $s->rank ?: '—' }}</span></div>
        <div style="{{ $rowStyle }}"><span style="{{ $labelStyle }}">Phone</span><span style="font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace);">{{ $s->phone ?: '—' }}</span></div>
        <div style="{{ $rowStyle }}"><span style="{{ $labelStyle }}">IPU user ID</span><span style="font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace); @if(! $s->ipu_user_id) {{ $emptyStyle }} @endif">{{ $s->ipu_user_id ?: '—' }}</span></div>
        <div style="{{ $rowStyle }}"><span style="{{ $labelStyle }}">IPU login code</span><span style="font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace); @if(! $s->ipu_login_code) {{ $emptyStyle }} @endif">{{ $s->ipu_login_code ?: '—' }}</span></div>
        <div style="{{ $rowStyle }}"><span style="{{ $labelStyle }}">Source</span><span @if(! $s->lead_source) style="{{ $emptyStyle }}" @endif>{{ $s->lead_source ?: '—' }}</span></div>
    </div>
</div>
