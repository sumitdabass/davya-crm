@props(['v' => 0, 'big' => false, 'inline' => false, 'danger' => null])
@php
    $value = (float) ($v ?? 0);
    $words = \App\Support\MoneyFormat::toIndianWords($value);
    $isDanger = $danger ?? ($value < 0);
    $numStyle = 'display:block; font-variant-numeric:tabular-nums; '
        . ($big ? 'font-size:24px; font-weight:600;' : 'font-weight:500;')
        . ($isDanger ? 'color:var(--danger);' : '');
    $wordStyle = 'display:block; font-size:'.($big ? '11px' : '10px')
        . '; color:var(--text-muted); font-weight:400; line-height:1.2; margin-top:2px;'
        . ($inline ? ' white-space:normal;' : ' white-space:nowrap;');
@endphp
<span style="display:inline-block; line-height:1.15; vertical-align:top;">
    <span style="{{ $numStyle }}">&#8377;{{ number_format($value, $big ? 2 : 2) }}</span>
    <span style="{{ $wordStyle }}" title="{{ $words }}">{{ $words }}</span>
</span>
