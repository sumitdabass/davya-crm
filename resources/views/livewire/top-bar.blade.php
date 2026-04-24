<div class="davya-topbar" style="background: var(--surface); border-bottom: 1px solid var(--border); padding: 10px 16px; display: flex; align-items: center; gap: 14px; font-size: var(--fs-12); position: sticky; top: 0; z-index: 30;">
    <a href="/admin" style="text-decoration: none; color: var(--brand-600); font-weight: 800; font-size: var(--fs-14); letter-spacing: 0.3px;">Davyas</a>

    <nav style="display: flex; gap: 2px;">
        @foreach ($tabs as $tab)
            @php $isActive = str_starts_with('/' . $currentPath, $tab['match']); @endphp
            <a href="{{ $tab['url'] }}"
               style="padding: 6px 10px; border-radius: var(--r-md); font-weight: {{ $isActive ? 600 : 500 }}; text-decoration: none; {{ $isActive ? 'color: var(--brand-700); background: var(--brand-50);' : 'color: var(--text-sub);' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>

    <button type="button"
            onclick="window.dispatchEvent(new CustomEvent('open-command-palette'))"
            style="flex: 1; background: var(--border-muted); border: 0; border-radius: var(--r-md); padding: 6px 10px; color: var(--text-muted); font-size: var(--fs-11); display: flex; align-items: center; gap: 8px; cursor: pointer; text-align: left;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        Jump to anything — student, stage, setting…
        <span style="margin-left: auto; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-sm); padding: 1px 5px; font-family: ui-monospace, monospace; font-size: var(--fs-10); color: var(--text-sub);">⌘K</span>
    </button>

    <a href="/admin/students/create"
       style="background: var(--brand-600); color: white; border-radius: var(--r-md); padding: 6px 10px; font-size: var(--fs-11); font-weight: 600; text-decoration: none;">
        + New Student
    </a>

    <a href="/admin/settings"
       style="text-decoration: none; color: var(--text-sub);"
       title="Settings">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </a>

    <span class="davya-owner-pill">
        <span class="av" style="background: {{ \App\Support\AvatarColor::forUserId($user?->id ?? 0) }};">{{ \App\Support\AvatarColor::initials($user?->name ?? '??') }}</span>
        {{ $user?->name }}
    </span>
</div>
