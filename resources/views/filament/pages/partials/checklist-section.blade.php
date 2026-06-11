{{-- props: $id (card id), $label, $icon (heroicon name), $urgent (bool), $rows (array) --}}
@php($count = count($rows))
<div class="dt-sec @if($count === 0) empty collapsed @endif" wire:key="dt-sec-{{ $id }}">
    <div class="dt-sec-h @if($urgent) urgent @endif"
         onclick="this.closest('.dt-sec').classList.toggle('collapsed')">
        <span class="ic">@svg($icon, 'w-4 h-4')</span>
        <span class="ttl">{{ $label }}</span>
        <span class="cnt">{{ $count }}</span>
        <span class="chev">@svg('heroicon-m-chevron-down', 'w-4 h-4')</span>
    </div>
    <div class="dt-sec-b">
        @forelse ($rows as $r)
            <div class="dt-row"
                 @if($r['student_id']) wire:click="$dispatch('open-student-peek', { studentId: {{ $r['student_id'] }} })" @endif>
                @if (! empty($r['time']))
                    <span class="dt-time">{{ $r['time'] }}</span>
                @elseif (! empty($r['dot']))
                    <span class="dt-dot" style="background: {{ $r['dot'] }};"></span>
                @endif
                <div class="bd">
                    <div class="nm">{{ $r['title'] }}</div>
                    <div class="sub">{{ $r['subtitle'] }}</div>
                </div>
                @if (! is_null($r['amount']))
                    <span class="dt-amt">₹{{ number_format($r['amount']) }}<span class="w">{{ \App\Support\MoneyFormat::toIndianWords((int) $r['amount']) }}</span></span>
                @elseif (! empty($r['pill']))
                    <span class="dt-pill">{{ $r['pill'] }}</span>
                @else
                    <span class="chev">@svg('heroicon-m-chevron-right', 'w-4 h-4')</span>
                @endif
            </div>
        @empty
            <div class="dt-clear">All clear — nothing pending.</div>
        @endforelse
    </div>
</div>
