<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        @forelse ($this->getCompanies() as $c)
            <a href="{{ url('/admin/books/'.$c->slug) }}"
               class="block p-4 rounded-lg border hover:shadow-md transition">
                <div class="text-lg font-semibold">{{ $c->name }}</div>
                <div class="text-sm text-gray-500">{{ $c->currency }} &middot; {{ $c->timezone }}</div>
            </a>
        @empty
            <div class="text-gray-500">No companies yet &mdash; click "+ New Company".</div>
        @endforelse
    </div>
</x-filament-panels::page>
