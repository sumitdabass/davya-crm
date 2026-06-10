@php
    $statePath = $getStatePath();
    $current = $getState();
    $stages = $getStages();
    $curIndex = collect($stages)->search(fn ($s) => $s['name'] === $current);
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="davya-stepwrap" wire:ignore.self>
        <div class="davya-step-cap">Pipeline · IPU Admission — tap a stage to move the lead</div>
        <div class="davya-stepper">
            @foreach ($stages as $i => $s)
                @php
                    $isDone = $curIndex !== false && $i < $curIndex;
                    $isCur = $s['name'] === $current;
                    $typeClass = $s['type'] === \App\Models\Stage::TYPE_WON
                        ? 'won'
                        : ($s['type'] === \App\Models\Stage::TYPE_LOST ? 'lost' : '');
                @endphp
                <button type="button"
                        class="davya-step {{ $isDone ? 'done' : '' }} {{ $isCur ? 'cur' : '' }} {{ $typeClass }}"
                        x-on:click="$wire.set(@js($statePath), @js($s['name']))">
                    <span class="dot"></span><span class="bar"></span>
                    <span class="lbl">{{ $s['name'] }}</span>
                </button>
            @endforeach
        </div>
    </div>
</x-dynamic-component>
