@props([
    'delta' => null,
    'priorLabel' => null,
    'series' => [],
    'color' => null,
])
@php
    $hasSpark = is_array($series) && count($series) >= 2;
    $hasDelta = $delta !== null && abs($delta) >= 0.1 && $priorLabel;
    if (! $hasSpark && ! $hasDelta) {
        return;
    }
    $deltaColor = $delta !== null && $delta < 0 ? 'var(--danger, #EF4444)' : 'var(--brand-700, #047857)';
    $arrow = $delta !== null && $delta < 0 ? '↓' : '↑';
    $pct = $delta !== null ? number_format(abs($delta), abs($delta) >= 100 ? 0 : 1) . '%' : null;
@endphp
<div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:6px; min-height:18px;">
    @if ($hasDelta)
        <span style="font-size:10.5px; color:{{ $deltaColor }}; font-weight:500; line-height:1; letter-spacing:0.1px;"
              title="{{ $arrow }} {{ $pct }} vs FY {{ $priorLabel }}">
            {{ $arrow }} {{ $pct }}
            <span style="color:var(--text-muted); font-weight:400;">vs FY {{ $priorLabel }}</span>
        </span>
    @else
        <span></span>
    @endif
    @if ($hasSpark)
        <x-spark :points="$series" :color="$color" :width="56" :height="16" :fill="$color" />
    @endif
</div>
