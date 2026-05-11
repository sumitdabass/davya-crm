<x-filament-panels::page>
    @php
        $activities = $this->getActivities();
        $subjectTypes = $this->getSubjectTypeOptions();
        $events = $this->getEventOptions();
        $causers = $this->getCauserOptions();
    @endphp

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Subject</label>
            <select wire:model.live="subjectTypeFilter" class="rounded border-gray-300 text-sm">
                <option value="">All subjects</option>
                @foreach ($subjectTypes as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Event</label>
            <select wire:model.live="eventFilter" class="rounded border-gray-300 text-sm">
                <option value="">All events</option>
                @foreach ($events as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">User</label>
            <select wire:model.live="causerIdFilter" class="rounded border-gray-300 text-sm">
                <option value="">All users</option>
                @foreach ($causers as $id => $email)
                    <option value="{{ $id }}">{{ $email }}</option>
                @endforeach
            </select>
        </div>
        @if ($subjectTypeFilter || $eventFilter || $causerIdFilter)
            <button wire:click="clearFilters" type="button"
                    class="text-xs text-blue-600 hover:underline self-end pb-1">Clear filters</button>
        @endif
    </div>

    <table class="w-full text-sm border rounded-lg overflow-hidden">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left p-2">When</th>
                <th class="text-left p-2">User</th>
                <th class="text-left p-2">Event</th>
                <th class="text-left p-2">Subject</th>
                <th class="text-left p-2">Changes</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($activities as $a)
                <tr class="border-t align-top">
                    <td class="p-2 whitespace-nowrap text-gray-600">
                        {{ $a->created_at->format('d M Y H:i') }}
                    </td>
                    <td class="p-2">
                        {{ $a->causer?->email ?? 'system' }}
                    </td>
                    <td class="p-2">
                        @php
                            $color = match($a->event) {
                                'created'  => 'bg-green-100 text-green-800',
                                'updated'  => 'bg-blue-100 text-blue-800',
                                'deleted'  => 'bg-red-100 text-red-800',
                                'restored' => 'bg-amber-100 text-amber-800',
                                default    => 'bg-gray-100 text-gray-800',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $color }}">{{ $a->event }}</span>
                    </td>
                    <td class="p-2">
                        <div class="font-medium">{{ class_basename($a->subject_type) }} #{{ $a->subject_id }}</div>
                        @if ($a->subject)
                            <div class="text-xs text-gray-500">{{ $a->subject->title ?? $a->subject->name ?? $a->subject->source ?? '—' }}</div>
                        @endif
                    </td>
                    <td class="p-2 text-xs text-gray-600 max-w-md">
                        @if (! empty($a->properties['attributes'] ?? []))
                            @foreach ($a->properties['attributes'] as $key => $val)
                                @php
                                    $oldVal = data_get($a->properties, "old.$key");
                                @endphp
                                <div>
                                    <span class="font-medium text-gray-700">{{ $key }}:</span>
                                    @if ($a->event === 'updated' && $oldVal !== null && $oldVal !== $val)
                                        <span class="text-red-600 line-through">{{ is_scalar($oldVal) ? $oldVal : json_encode($oldVal) }}</span>
                                        →
                                        <span class="text-green-600">{{ is_scalar($val) ? $val : json_encode($val) }}</span>
                                    @else
                                        <span>{{ is_scalar($val) ? $val : json_encode($val) }}</span>
                                    @endif
                                </div>
                            @endforeach
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-4 text-gray-500 text-center">No activity yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-4">
        {{ $activities->links() }}
    </div>
</x-filament-panels::page>
