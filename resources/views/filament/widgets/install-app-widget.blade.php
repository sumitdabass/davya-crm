@php
    $ua = strtolower(request()->userAgent() ?? '');
    $isIos = str_contains($ua, 'iphone') || str_contains($ua, 'ipad');
    $isAndroid = str_contains($ua, 'android');
    $isMobile = $isIos || $isAndroid;
@endphp

<div
    x-data="{
        deferredPrompt: window.__davyaInstallPrompt || null,
        installed: false,
        standalone: false,
        isIos: @js($isIos),
        isAndroid: @js($isAndroid),
        isMobile: @js($isMobile),
        showHint: false,
        hintTitle: '',
        hintSteps: [],

        init() {
            this.standalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;

            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                this.deferredPrompt = e;
                window.__davyaInstallPrompt = e;
            });

            window.addEventListener('appinstalled', () => {
                this.installed = true;
                this.deferredPrompt = null;
                window.__davyaInstallPrompt = null;
            });
        },

        install() {
            if (this.deferredPrompt) {
                this.deferredPrompt.prompt();
                this.deferredPrompt.userChoice.then(() => {
                    this.deferredPrompt = null;
                    window.__davyaInstallPrompt = null;
                });
                return;
            }
            if (this.isIos) {
                this.hintTitle = 'On iPhone / iPad (Safari):';
                this.hintSteps = [
                    'Tap the Share icon at the bottom of Safari.',
                    'Scroll down and tap Add to Home Screen.',
                    'Tap Add. Davya will appear like a native app.',
                ];
            } else if (this.isAndroid) {
                this.hintTitle = 'On Android:';
                this.hintSteps = [
                    'Open this page in Chrome (not an in-app browser).',
                    'Tap the three-dot menu in the top-right.',
                    'Tap Install app or Add to Home screen.',
                ];
            } else {
                this.hintTitle = 'On desktop Chrome / Edge:';
                this.hintSteps = [
                    'Look for the install icon at the right of the address bar (a small monitor with a down-arrow).',
                    'Click it, then Install.',
                    'The app opens in its own window — no browser tabs.',
                ];
            }
            this.showHint = true;
        },

        get shouldShow() {
            return !this.standalone && !this.installed;
        },
    }"
    x-show="shouldShow"
    x-cloak
>
    <x-filament::section>
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-900/20">
                    <img src="/davyas-icon-192.png" alt="" class="h-10 w-10 rounded-full" />
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        Install Davya CRM as an app
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        One-tap access, standalone window, no browser bar. Works on phone and desktop.
                    </p>
                </div>
            </div>

            <div class="flex gap-2">
                <x-filament::button
                    x-on:click="install()"
                    icon="heroicon-m-arrow-down-tray"
                    color="primary"
                >
                    Install
                </x-filament::button>
            </div>
        </div>

        <div
            x-show="showHint"
            x-collapse
            class="mt-4 rounded-lg bg-gray-50 p-4 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-300"
        >
            <p class="font-medium text-gray-950 dark:text-white mb-2" x-text="hintTitle"></p>
            <ol class="list-decimal list-inside space-y-1">
                <template x-for="step in hintSteps">
                    <li x-text="step"></li>
                </template>
            </ol>
        </div>
    </x-filament::section>
</div>
