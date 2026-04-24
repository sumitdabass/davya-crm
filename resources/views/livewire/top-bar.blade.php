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

    @if ($canSettings)
        <a href="/admin/settings"
           style="text-decoration: none; color: var(--text-sub);"
           title="Settings">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        </a>
    @endif

    <div x-data="{ open: false }" style="position: relative;">
        <button type="button"
                @click="open = !open"
                class="davya-owner-pill"
                style="cursor: pointer; border: 0; background: transparent; font: inherit;">
            <span class="av" style="background: {{ \App\Support\AvatarColor::forUserId($user?->id ?? 0) }};">{{ \App\Support\AvatarColor::initials($user?->name ?? '??') }}</span>
            {{ $user?->name }}
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-left: 2px;"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div x-show="open" @click.outside="open = false" x-cloak
             style="position: absolute; right: 0; top: calc(100% + 6px); min-width: 200px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-md); box-shadow: 0 6px 20px rgba(0,0,0,0.08); z-index: 40; overflow: hidden;">
            <div style="padding: 10px 12px; border-bottom: 1px solid var(--border); font-size: var(--fs-11); color: var(--text-muted);">
                {{ $user?->email }}
            </div>
            <a href="{{ url('/admin/users/' . ($user?->id ?? 0) . '/edit') }}"
               style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; color: var(--text); text-decoration: none; font-size: var(--fs-12);"
               onmouseover="this.style.background='var(--border-muted)'" onmouseout="this.style.background=''">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                My profile
            </a>
            <a href="/admin/change-password"
               style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; color: var(--text); text-decoration: none; font-size: var(--fs-12);"
               onmouseover="this.style.background='var(--border-muted)'" onmouseout="this.style.background=''">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Change password
            </a>
            <button type="button"
                    @click="document.documentElement.classList.toggle('dark'); try { localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light'); } catch(e) {}; open = false;"
                    style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; width: 100%; text-align: left; border: 0; background: transparent; color: var(--text); font-size: var(--fs-12); cursor: pointer;"
                    onmouseover="this.style.background='var(--border-muted)'" onmouseout="this.style.background=''">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                Toggle dark mode
            </button>
            <button type="button"
                    x-data="{
                        install() {
                            const p = window.__davyaInstallPrompt;
                            if (p) {
                                p.prompt();
                                p.userChoice.finally(() => { window.__davyaInstallPrompt = null; });
                                open = false;
                                return;
                            }
                            const ua = (navigator.userAgent || '').toLowerCase();
                            let msg;
                            if (ua.includes('iphone') || ua.includes('ipad')) {
                                msg = 'iPhone / iPad: tap the Share icon in Safari, scroll to Add to Home Screen, then Add.';
                            } else if (ua.includes('android')) {
                                msg = 'Android: open this page in Chrome (not an in-app browser), tap the three-dot menu, then Install app.';
                            } else {
                                msg = 'Desktop Chrome / Edge: click the install icon at the right of the address bar (a small monitor with a down-arrow), then Install. If you do not see it, the app is either already installed or your browser does not support installation.';
                            }
                            alert(msg);
                            open = false;
                        }
                    }"
                    @click="install()"
                    style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; width: 100%; text-align: left; border: 0; background: transparent; color: var(--text); font-size: var(--fs-12); cursor: pointer;"
                    onmouseover="this.style.background='var(--border-muted)'" onmouseout="this.style.background=''">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Install app
            </button>
            <div style="border-top: 1px solid var(--border);"></div>
            <form method="POST" action="{{ route('filament.admin.auth.logout') }}" style="margin: 0;">
                @csrf
                <button type="submit"
                        style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; width: 100%; text-align: left; border: 0; background: transparent; color: #b91c1c; font-size: var(--fs-12); cursor: pointer;"
                        onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background=''">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Log out
                </button>
            </form>
        </div>
    </div>
</div>
