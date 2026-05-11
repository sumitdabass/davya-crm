<div style="display:grid; gap:6px; font-size:13px;">
    @foreach ($rows as $row)
        @php
            $label = $row[0] ?? '';
            $value = $row[1] ?? '';
            $isTotal = $row[2] ?? false;
        @endphp
        <div style="display:flex; justify-content:space-between; align-items:baseline; gap:12px; padding:{{ $isTotal ? '10px 0 4px' : '4px 0' }}; {{ $isTotal ? 'border-top:1px solid var(--border); margin-top:4px;' : '' }}">
            <span style="color:{{ $isTotal ? 'var(--text)' : 'var(--text-sub)' }}; {{ $isTotal ? 'font-weight:600;' : '' }}">{{ $label }}</span>
            @if ($value !== '')
                <span style="color:var(--text); font-family:var(--font-display); font-weight:{{ $isTotal ? '700' : '500' }}; font-size:{{ $isTotal ? '16px' : '14px' }}; white-space:nowrap;">{{ $value }}</span>
            @endif
        </div>
    @endforeach
</div>
