<div class="davya-ai-drawer" x-data="{ open: $wire.entangle('open').defer ?? false }">
    <div class="davya-ai-thread" style="max-height:60vh; overflow-y:auto; padding:1rem;">
        @forelse ($thread as $m)
            <div class="davya-ai-msg davya-ai-msg--{{ $m['role'] }}">
                <div class="davya-ai-msg-role">{{ ucfirst($m['role']) }}</div>
                <div class="davya-ai-msg-content">{!! nl2br(e($m['content'])) !!}</div>
            </div>
        @empty
            <div class="davya-ai-empty">Ask a question about ipu.co.in admissions.</div>
        @endforelse

        @if ($busy)
            <div class="davya-ai-busy" wire:loading.delay>Thinking…</div>
        @endif
        @if ($error)
            <div class="davya-ai-error" role="alert">{{ $error }}</div>
        @endif
    </div>

    <form wire:submit.prevent="ask" class="davya-ai-form" style="display:flex; gap:.5rem; padding:1rem;">
        <textarea wire:model.live="input"
                  placeholder="e.g. BBA fee at VIPS-TC?"
                  rows="2"
                  style="flex:1; resize:vertical;"
                  @keydown.cmd.enter="$wire.ask()"
                  @keydown.ctrl.enter="$wire.ask()"></textarea>
        <button type="submit" class="davya-action davya-action--solid" wire:loading.attr="disabled">Send</button>
        <button type="button" class="davya-action davya-action--ghost-light" wire:click="newChat">New chat</button>
    </form>
</div>
