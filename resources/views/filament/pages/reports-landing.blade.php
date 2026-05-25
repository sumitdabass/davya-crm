<x-filament-panels::page>
    <x-crumbs :trail="[]" />

    <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
        Pick a report to open. Cards visible to you reflect your role's access.
    </p>

    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ($this->getCards() as $card)
            <a href="{{ $card['url'] }}"
               class="group block rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5 hover:border-primary-500 hover:shadow-md transition">
                <div class="flex items-start gap-3">
                    <div class="shrink-0 rounded-md p-2 bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-300">
                        <x-filament::icon :icon="$card['icon']" class="w-5 h-5" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                {{ $card['title'] }}
                            </h3>
                            <span class="text-sm text-primary-600 dark:text-primary-400 opacity-0 group-hover:opacity-100 transition">Open →</span>
                        </div>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                            {{ $card['desc'] }}
                        </p>
                    </div>
                </div>
            </a>
        @endforeach
    </div>
</x-filament-panels::page>
