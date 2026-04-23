<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex gap-2">
            <button type="button" wire:click="$set('activeTab', 'live')" style="padding:6px 12px; border-radius:6px; background-color: {{ $activeTab === 'live' ? '#059669' : '#e5e7eb' }}; color: {{ $activeTab === 'live' ? 'white' : 'black' }};">
                Sections &amp; Fields
            </button>
            <button type="button" wire:click="$set('activeTab', 'archived')" style="padding:6px 12px; border-radius:6px; background-color: {{ $activeTab === 'archived' ? '#059669' : '#e5e7eb' }}; color: {{ $activeTab === 'archived' ? 'white' : 'black' }};">
                Archived
            </button>
        </div>

        @if ($activeTab === 'live')
            <div class="grid grid-cols-12 gap-4">
                <aside class="col-span-3 border rounded p-3">
                    <h3 class="font-semibold mb-2">Sections</h3>
                    <ul class="space-y-1">
                        @foreach ($this->sections() as $section)
                            <li>
                                <button type="button" wire:click="$set('selectedSectionId', {{ $section->id }})" class="w-full text-left px-2 py-1 rounded {{ $selectedSectionId === $section->id ? 'bg-emerald-100' : '' }}">
                                    {{ $section->name }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </aside>
                <main class="col-span-9 border rounded p-3">
                    <h3 class="font-semibold mb-2">Fields</h3>
                    <ul class="space-y-1">
                        @foreach ($this->fieldsForSelectedSection() as $field)
                            <li class="flex items-center gap-3">
                                <span>{{ $field->label }}</span>
                                <span class="text-xs text-gray-500">{{ $field->key }}</span>
                                <span class="text-xs px-2 py-0.5 bg-gray-200 rounded">{{ $field->type }}</span>
                                @if ($field->is_built_in)
                                    <span class="text-xs px-2 py-0.5 bg-amber-100 rounded">🔒 built-in</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </main>
            </div>
        @else
            <div class="border rounded p-3">
                <h3 class="font-semibold mb-2">Archived fields</h3>
                <ul class="space-y-1">
                    @foreach ($this->archivedFields() as $field)
                        <li>{{ $field->label }} — archived {{ $field->archived_at->diffForHumans() }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
