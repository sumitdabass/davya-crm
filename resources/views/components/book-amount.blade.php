@props(['v' => 0, 'big' => false, 'inline' => false, 'danger' => null])
@php
    $value = (float) ($v ?? 0);
    $words = \App\Support\MoneyFormat::toIndianWords($value);
    $isDanger = $danger ?? ($value < 0);
    // Number: kept on one line (tabular-nums needs nowrap), but with overflow
    // handling so a too-wide value doesn't escape the container.
    $numStyle = 'display:block; font-variant-numeric:tabular-nums; '
        . 'white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; '
        . ($big ? 'font-size:22px; font-weight:600;' : 'font-weight:500;')
        . ($isDanger ? 'color:var(--danger);' : '');
    // Words: always allowed to wrap — used to be nowrap by default which made
    // long figures like "Sixteen Lakh Thirty Four Thousand Five Hundred Rupees"
    // overflow narrow tiles. Word-break helps in extreme cases.
    $wordStyle = 'display:block; font-size:'.($big ? '11px' : '10px')
        . '; color:var(--text-muted); font-weight:400; line-height:1.2; margin-top:2px;'
        . ' white-space:normal; overflow-wrap:anywhere; word-break:break-word; max-width:100%;';
@endphp
<span style="display:inline-block; min-width:0; max-width:100%; line-height:1.15; vertical-align:top;">
    <span style="{{ $numStyle }}" title="&#8377;{{ number_format($value, 2) }}">&#8377;{{ number_format($value, $big ? 2 : 2) }}</span>
    <span style="{{ $wordStyle }}" title="{{ $words }}">{{ $words }}</span>
</span>
