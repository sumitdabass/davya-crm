<div wire:key="command-palette">
    @if ($isOpen)
        <div style="position: fixed; inset: 0; background: rgba(17,24,39,.3); z-index: 60; display: flex; align-items: flex-start; justify-content: center; padding-top: 12vh;"
             wire:click="close">
            <div style="background: var(--surface); border-radius: var(--r-lg); width: 600px; max-width: 92vw; box-shadow: var(--elev-2); overflow: hidden;"
                 wire:click.stop>
                <input type="text"
                       wire:model.live.debounce.150ms="query"
                       autofocus
                       placeholder="Search students, pages, actions…"
                       style="width: 100%; padding: 14px 16px; border: 0; font-size: var(--fs-14); outline: 0; border-bottom: 1px solid var(--border);">
                <div style="max-height: 60vh; overflow-y: auto;">
                    @if (count($students))
                        <div class="davya-section-card-title" style="padding: 8px 16px 4px; margin: 0;">Students</div>
                        @foreach ($students as $s)
                            <a href="#"
                               wire:click.prevent="$dispatch('open-student-peek', { studentId: {{ $s['id'] }} }); $dispatchSelf('close')"
                               style="display: block; padding: 8px 16px; text-decoration: none; color: var(--text); font-size: var(--fs-12); border-bottom: 1px solid var(--border-muted);">
                                <strong>{{ $s['name'] }}</strong>
                                <span style="color: var(--text-sub); margin-left: 8px;">{{ $s['phone'] ?? '' }} · {{ $s['course'] ?? '—' }}</span>
                            </a>
                        @endforeach
                    @endif

                    <div class="davya-section-card-title" style="padding: 8px 16px 4px; margin: 0;">Pages</div>
                    @foreach ($pages as $p)
                        <a href="{{ $p['url'] }}"
                           style="display: block; padding: 8px 16px; text-decoration: none; color: var(--text); font-size: var(--fs-12); border-bottom: 1px solid var(--border-muted);">
                            {{ $p['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
