@php
    use App\Today\ChecklistSections;
    use App\Today\SectionRegistry;

    $cards = $this->cards();
    $statCards = array_values(array_filter($cards, fn ($c) => $c->type() === 'stat'));
    $listCards = array_values(array_filter($cards, fn ($c) => $c->type() === 'list'));
    $sections  = app(ChecklistSections::class);
    $viewer    = auth()->user();
@endphp

<x-filament-panels::page>
    <div class="davya-today">
        <div class="dt-h">
            <div>
                <div class="t">Today</div>
                <div class="d">{{ now('Asia/Kolkata')->format('l, j F Y') }}</div>
            </div>
            <button type="button"
                    wire:click="$dispatch('open-customize-modal', { surface: 'today' })"
                    class="davya-action davya-action--solid">Customize</button>
        </div>

        @if (count($cards) === 0)
            <div class="dt-sec"><div class="dt-clear" style="text-align:center;">
                You've hidden all cards.
                <button type="button" wire:click="$dispatch('reset-cards-to-defaults', { surface: 'today' })"
                        class="davya-action" style="text-decoration:underline;">Reset to defaults</button>
            </div></div>
        @else
            {{-- stats strip --}}
            @if (count($statCards))
                <div class="dt-stats">
                    @foreach ($statCards as $card)
                        <button type="button" class="dt-stat"
                                wire:click="$dispatch('open-slide-over', { cardId: '{{ $card->id() }}' })">
                            {!! $card->render($viewer) !!}
                            <div class="l">{{ $card->label() }}</div>
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- checklist sections, in prefs order --}}
            @foreach ($listCards as $card)
                @php($d = SectionRegistry::descriptor($card->id()))
                @if ($d)
                    @include('filament.pages.partials.checklist-section', [
                        'id'     => $card->id(),
                        'label'  => $d['label'],
                        'icon'   => $d['icon'],
                        'urgent' => $d['urgent'],
                        'rows'   => $sections->forCard($card->id(), $viewer),
                    ])
                @endif
            @endforeach
        @endif
    </div>

    @livewire(\App\Livewire\StudentSlideOver::class)
    @livewire(\App\Livewire\CustomizeCardsModal::class)

    <div
        x-data="{ toast: null }"
        x-on:card-removed.window="toast = $event.detail; setTimeout(() => { if (toast === $event.detail) toast = null }, 8000);"
        style="position: fixed; bottom: 16px; right: 16px; z-index: 9997;"
    >
        <template x-if="toast">
            <div style="display:flex;align-items:center;gap:12px;padding:10px 16px;background:#111827;color:#fff;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.25);font-size:13px;">
                <span>Removed <span x-text="toast.cardId"></span>.</span>
                <button type="button" class="davya-action davya-action--ghost-light"
                        x-on:click="$wire.dispatch('undo-remove', { surface: toast.surface, cardId: toast.cardId, position: toast.position }); toast = null;">Undo</button>
            </div>
        </template>
    </div>
</x-filament-panels::page>
