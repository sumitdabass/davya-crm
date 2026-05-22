<div class="davya-ai-drawer"
     x-data="{ open: false }"
     x-on:ai-drawer:open.window="open = true; $nextTick(() => $refs.textarea && $refs.textarea.focus())"
     x-on:keydown.escape.window="open = false">

    <div class="davya-ai-backdrop" :data-open="open" x-on:click="open = false"></div>

    <aside class="davya-ai-drawer-root"
           :data-open="open"
           role="dialog"
           aria-label="Knowledge agent"
           aria-modal="true">

        <header class="davya-ai-head">
            <div class="davya-ai-head-row">
                <h2 class="davya-ai-title">Knowledge agent</h2>
                <button type="button" class="davya-ai-close" x-on:click="open = false" aria-label="Close">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <div class="davya-ai-sub">grounded in ipu.co.in</div>
        </header>

        <div class="davya-ai-thread" x-ref="thread">
            @forelse ($thread as $m)
                @php
                    $content = $m['content'] ?? '';
                    $cites   = $m['citations'] ?? [];
                    // Strip the auto-appended Source line from the text (rendered as badges instead).
                    $content = preg_replace('/\n*Source:.*$/s', '', $content);
                    $content = trim($content);
                @endphp
                <div class="davya-ai-msg davya-ai-msg--{{ $m['role'] }}">
                    <div class="davya-ai-role">{{ $m['role'] === 'user' ? 'You' : 'Assistant' }}</div>
                    @if ($content !== '')
                        <div class="davya-ai-text">{!! nl2br(e($content)) !!}</div>
                    @endif
                    @if (! empty($cites))
                        <div class="davya-ai-cites">
                            @foreach ($cites as $slug)
                                <a class="davya-ai-cite"
                                   href="https://ipu.co.in/{{ $slug }}"
                                   target="_blank"
                                   rel="noopener noreferrer">{{ $slug }}</a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="davya-ai-empty">
                    <div class="davya-ai-empty-mark" aria-hidden="true">✦</div>
                    <div class="davya-ai-empty-line">What can I help you find?</div>
                    <div class="davya-ai-empty-hint">ask about admissions, fees, hostels, cutoffs</div>
                </div>
            @endforelse

            @if ($busy)
                <div class="davya-ai-busy" wire:loading.delay.class.remove="is-hidden">reading the source…</div>
            @endif

            @if ($error)
                <div class="davya-ai-error" role="alert">{{ $error }}</div>
            @endif
        </div>

        <form class="davya-ai-foot" wire:submit.prevent="ask">
            <textarea x-ref="textarea"
                      class="davya-ai-textarea"
                      wire:model.live.debounce.150ms="input"
                      placeholder="BBA fee at VIPS-TC? MAIT hostel? cutoffs for AIDS?"
                      rows="3"
                      x-on:keydown.cmd.enter.prevent="$wire.ask()"
                      x-on:keydown.ctrl.enter.prevent="$wire.ask()"></textarea>
            <div class="davya-ai-foot-row">
                <div class="davya-ai-hint"><kbd>⌘</kbd> <kbd>↵</kbd> to ask</div>
                <div class="davya-ai-foot-actions">
                    @if (! empty($thread))
                        <button type="button" class="davya-ai-reset" wire:click="newChat">↻ new chat</button>
                    @endif
                    <button type="submit" class="davya-ai-send" wire:loading.attr="disabled">Ask</button>
                </div>
            </div>
        </form>
    </aside>
</div>
