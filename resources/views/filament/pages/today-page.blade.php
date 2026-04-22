<x-filament-panels::page>
    @php
        $now = now('Asia/Kolkata');
    @endphp

    <div class="text-sm text-gray-500 dark:text-gray-400 mb-2">
        {{ $now->format('l, j M Y') }}
    </div>

    <x-filament-widgets::widgets
        :widgets="$this->getVisibleHeaderWidgets()"
        :columns="$this->getHeaderWidgetsColumns()"
        :data="$this->getWidgetData()"
    />
</x-filament-panels::page>
