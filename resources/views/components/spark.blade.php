@props([
    'points' => [],
    'width' => 64,
    'height' => 18,
    'color' => null,
    'fill' => null,
    'strokeWidth' => 1.5,
])
@php
    $pts = array_values(array_map(fn ($v) => (float) $v, $points));
    $n = count($pts);
    if ($n < 2) {
        $svg = null;
    } else {
        $min = min($pts);
        $max = max($pts);
        $range = max($max - $min, 1e-9);
        $stepX = $width / ($n - 1);
        $pad = max(2.0, (float) $strokeWidth);
        $usable = max($height - 2 * $pad, 1.0);
        $coords = [];
        foreach ($pts as $i => $v) {
            $x = round($i * $stepX, 2);
            $y = round($pad + (1 - ($v - $min) / $range) * $usable, 2);
            $coords[] = "{$x},{$y}";
        }
        $strokeColor = $color ?? 'var(--brand-600, #059669)';
        $fillColor = $fill ?? 'none';
        $polyline = implode(' ', $coords);
        if ($fillColor !== 'none') {
            $first = explode(',', $coords[0]);
            $last = explode(',', $coords[$n - 1]);
            $area = "{$first[0]},{$height} " . $polyline . " {$last[0]},{$height}";
        }
        $lastIdx = $n - 1;
        $lastCoord = explode(',', $coords[$lastIdx]);
        $svg = compact('width','height','polyline','strokeColor','fillColor','strokeWidth','lastCoord','area');
    }
@endphp
@if ($svg)
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {{ $width }} {{ $height }}"
         style="width:{{ $width }}px; height:{{ $height }}px; display:block; overflow:visible;"
         aria-hidden="true">
        @if ($fill && $fill !== 'none')
            <polyline points="{{ $area }}" fill="{{ $strokeColor }}" fill-opacity="0.12" stroke="none" />
        @endif
        <polyline points="{{ $polyline }}" fill="none" stroke="{{ $strokeColor }}"
                  stroke-width="{{ $strokeWidth }}" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="{{ $lastCoord[0] }}" cy="{{ $lastCoord[1] }}" r="{{ max(1.5, $strokeWidth) }}" fill="{{ $strokeColor }}" />
    </svg>
@endif
