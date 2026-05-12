<div x-data="{ visible: true }"
     @click.outside="visible = false"
     @focusin="visible = true"
     @keydown.escape.window="visible = false"
     @keydown.window="(e) => { if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); $el.querySelector('input').focus(); $el.querySelector('input').select(); visible = true; } }"
     style="flex: 1; position: relative;">
    <div style="display: flex; align-items: center; gap: 8px; background: var(--border-muted); border-radius: var(--r-md); padding: 6px 10px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--text-muted); flex-shrink: 0;"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text"
               wire:model.live.debounce.200ms="query"
               placeholder="Search students by name, phone, email…"
               style="flex: 1; border: 0; background: transparent; outline: 0; color: var(--text); font-size: var(--fs-12);">
        <kbd style="font-family: var(--font-display, system-ui); font-size: 10px; color: var(--text-muted); background: var(--surface); border: 1px solid var(--border); border-radius: 3px; padding: 1px 5px; flex-shrink: 0;">⌘K</kbd>
        @if (strlen(trim($query)) > 0)
            <button type="button"
                    wire:click="$set('query', '')"
                    style="border: 0; background: transparent; color: var(--text-muted); cursor: pointer; padding: 0; line-height: 0;"
                    title="Clear">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        @endif
    </div>

    @if (mb_strlen(trim($query)) >= 2)
        <div x-show="visible" x-cloak
             style="position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-md); box-shadow: 0 6px 20px rgba(0,0,0,0.08); z-index: 50; max-height: 60vh; overflow-y: auto;">
            @if (count($students) === 0)
                <div style="padding: 12px 14px; color: var(--text-muted); font-size: var(--fs-12);">
                    No students match "{{ $query }}".
                </div>
            @else
                @foreach ($students as $s)
                    <a href="{{ url('/admin/students/' . $s['id'] . '/edit') }}"
                       style="display: flex; align-items: baseline; gap: 8px; padding: 10px 14px; border-bottom: 1px solid var(--border-muted); text-decoration: none; color: var(--text); font-size: var(--fs-12);"
                       onmouseover="this.style.background='var(--border-muted)'"
                       onmouseout="this.style.background=''">
                        <strong style="font-size: var(--fs-13);">{{ $s['name'] ?: '—' }}</strong>
                        <span style="color: var(--text-sub);">{{ $s['phone'] ?? '' }}</span>
                        <span style="color: var(--text-muted);">·</span>
                        <span style="color: var(--text-sub);">{{ $s['course'] ?: '—' }}</span>
                        <span style="margin-left: auto; color: var(--text-muted); font-size: var(--fs-11);">{{ $s['stage'] }}</span>
                    </a>
                @endforeach
            @endif
        </div>
    @endif
</div>
