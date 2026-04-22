@php
    $isIos = str_contains(strtolower(request()->userAgent() ?? ''), 'iphone')
        || str_contains(strtolower(request()->userAgent() ?? ''), 'ipad');
@endphp

<div
    x-data="{
        deferredPrompt: null,
        installed: false,
        standalone: false,
        isIos: @js($isIos),
        showIosHint: false,

        init() {
            this.standalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;

            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                this.deferredPrompt = e;
            });

            window.addEventListener('appinstalled', () => {
                this.installed = true;
                this.deferredPrompt = null;
            });
        },

        install() {
            if (this.isIos && !this.standalone) {
                this.showIosHint = true;
                return;
            }
            if (!this.deferredPrompt) return;
            this.deferredPrompt.prompt();
            this.deferredPrompt.userChoice.then(() => {
                this.deferredPrompt = null;
            });
        },

        get shouldShow() {
            if (this.standalone || this.installed) return false;
            if (this.isIos) return true;
            return this.deferredPrompt !== null;
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
                        Install Davya CRM on your phone
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Add to your home screen for one-tap access, no browser bar, works like a native app.
                    </p>
                </div>
            </div>

            <div class="flex gap-2">
                <template x-if="!isIos">
                    <x-filament::button
                        x-on:click="install()"
                        icon="heroicon-m-arrow-down-tray"
                        color="primary"
                    >
                        Install as App
                    </x-filament::button>
                </template>

                <template x-if="isIos">
                    <x-filament::button
                        x-on:click="install()"
                        icon="heroicon-m-arrow-up-on-square"
                        color="primary"
                    >
                        How to install
                    </x-filament::button>
                </template>
            </div>
        </div>

        <div
            x-show="showIosHint"
            x-collapse
            class="mt-4 rounded-lg bg-gray-50 p-4 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-300"
        >
            <p class="font-medium text-gray-950 dark:text-white mb-2">On iPhone / iPad:</p>
            <ol class="list-decimal list-inside space-y-1">
                <li>Tap the <span class="font-semibold">Share</span> icon at the bottom of Safari.</li>
                <li>Scroll down and tap <span class="font-semibold">Add to Home Screen</span>.</li>
                <li>Tap <span class="font-semibold">Add</span>. Davya will appear on your home screen like a native app.</li>
            </ol>
        </div>
    </x-filament::section>
</div>
