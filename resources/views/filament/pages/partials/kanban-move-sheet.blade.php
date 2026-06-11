{{-- Guided move sheet — inside the kanbanMobile() Alpine scope (Task 5). --}}
<div class="pl-sheet-backdrop" x-show="move.open" x-cloak x-on:click="move.open = false" x-transition.opacity></div>
<div class="pl-sheet" x-show="move.open" x-cloak x-transition>
    <div class="pl-sheet-h">Move <span x-text="move.name"></span> forward</div>

    <template x-if="move.next">
        <button type="button" class="pl-sheet-fwd" x-on:click="go(move.next)">
            → <span x-text="move.next"></span>
        </button>
    </template>

    <template x-if="move.prev">
        <button type="button" class="pl-sheet-row" x-on:click="go(move.prev)">
            ⤺ Back to <span x-text="move.prev"></span>
        </button>
    </template>

    <details class="pl-sheet-any">
        <summary>▾ Any stage</summary>
        <template x-for="st in stages" :key="st">
            <button type="button" class="pl-sheet-row"
                    :class="st === move.from ? 'cur' : ''"
                    :disabled="st === move.from"
                    x-on:click="go(st)">
                <span x-text="st"></span>
                <span class="c" x-show="st === move.from">current</span>
            </button>
        </template>
    </details>

    <button type="button" class="pl-sheet-cancel" x-on:click="move.open = false">Cancel</button>
</div>
