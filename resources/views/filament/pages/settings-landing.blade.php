<x-filament-panels::page>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
        @foreach ($this->getTiles() as $tile)
            <a href="{{ $tile['url'] }}" class="davya-section-card" style="text-decoration: none; color: inherit; display: block; cursor: pointer;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                    <x-dynamic-component :component="$tile['icon']" style="width: 20px; height: 20px; color: var(--brand-600);" />
                    <span style="font-weight: 700; font-size: var(--fs-14); color: var(--text);">{{ $tile['label'] }}</span>
                </div>
                <p style="font-size: var(--fs-12); color: var(--text-sub); margin: 0;">{{ $tile['desc'] }}</p>
            </a>
        @endforeach
    </div>
</x-filament-panels::page>
