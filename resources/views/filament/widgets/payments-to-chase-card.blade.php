<div style="padding: 8px 16px;">
    @forelse ($rows as $r)
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f1f1;font-size:13px;">
            <span>{{ $r->name }}</span>
            <span style="font-variant-numeric:tabular-nums;">₹{{ number_format($r->pending_amount) }}</span>
        </div>
    @empty
        <div style="padding:8px 0;color:#6b7280;font-size:13px;">Nothing to chase.</div>
    @endforelse
</div>
