@php
    $ua = strtolower(request()->userAgent() ?? '');
    $isIos = str_contains($ua, 'iphone') || str_contains($ua, 'ipad');
    $isAndroid = str_contains($ua, 'android');
@endphp

<x-filament-panels::page>
    <div
        x-data="{
            deferredPrompt: window.__davyaInstallPrompt || null,
            installed: false,
            standalone: false,
            isIos: @js($isIos),
            isAndroid: @js($isAndroid),
            showHint: false,
            hintTitle: '',
            hintSteps: [],

            init() {
                this.standalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;
                this.installed = this.standalone;

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
        }"
    >
        <x-filament::section>
            <div x-show="installed" class="flex items-center gap-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 p-4">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 text-emerald-600 dark:text-emerald-400" />
                <div>
                    <p class="font-semibold text-emerald-900 dark:text-emerald-100">App is installed</p>
                    <p class="text-sm text-emerald-700 dark:text-emerald-300">You're viewing Davya CRM in standalone mode.</p>
                </div>
            </div>

            <div x-show="!installed" class="space-y-6">
                <div class="flex items-center gap-4">
                    <div class="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-900/20">
                        <img src="/davyas-icon-192.png" alt="" class="h-12 w-12 rounded-full" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-950 dark:text-white">
                            Install Davya CRM as an app
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            One-tap access, standalone window, no browser bar. Works on phone and desktop.
                        </p>
                    </div>
                </div>

                <div>
                    <x-filament::button
                        x-on:click="install()"
                        icon="heroicon-m-arrow-down-tray"
                        color="primary"
                        size="lg"
                    >
                        Install
                    </x-filament::button>
                </div>

                <div
                    x-show="showHint"
                    x-collapse
                    class="rounded-lg bg-gray-50 p-4 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-300"
                >
                    <p class="font-medium text-gray-950 dark:text-white mb-2" x-text="hintTitle"></p>
                    <ol class="list-decimal list-inside space-y-1">
                        <template x-for="step in hintSteps">
                            <li x-text="step"></li>
                        </template>
                    </ol>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
