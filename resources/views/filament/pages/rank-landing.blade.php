<x-filament-panels::page>
    <x-crumbs :trail="[]" />

    @if ($primary = $this->getPrimaryCard())
        <div class="mt-4">
            <a href="{{ $primary['url'] }}"
               class="group flex items-start gap-4 rounded-lg border border-primary-200 dark:border-primary-700 bg-primary-50 dark:bg-primary-900/20 p-5 hover:border-primary-500 hover:shadow-md transition">
                <div class="shrink-0 rounded-md p-3 bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300">
                    <x-filament::icon :icon="$primary['icon']" class="w-6 h-6" />
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-baseline justify-between gap-2">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $primary['title'] }}</h3>
                        <span class="text-sm text-primary-700 dark:text-primary-300 opacity-0 group-hover:opacity-100 transition">Open →</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                        {{ $primary['desc'] }}
                    </p>
                </div>
            </a>
        </div>
    @endif

    @if (($manage = $this->getManageCards()))
        <div class="mt-8">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">
                Manage source data
            </h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach ($manage as $card)
                    <a href="{{ $card['url'] }}"
                       class="group block rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 hover:border-primary-500 hover:shadow-sm transition">
                        <div class="flex items-center gap-2">
                            <div class="shrink-0 rounded-md p-1.5 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">
                                <x-filament::icon :icon="$card['icon']" class="w-4 h-4" />
                            </div>
                            <h5 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $card['title'] }}</h5>
                        </div>
                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-400 leading-snug">
                            {{ $card['desc'] }}
                        </p>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</x-filament-panels::page>
