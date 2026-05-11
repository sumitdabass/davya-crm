<x-filament-panels::page>
    @php
        $activities = $this->getActivities();
        $subjectTypes = $this->getSubjectTypeOptions();
        $events = $this->getEventOptions();
        $causers = $this->getCauserOptions();
    @endphp

    <div class="davya-books-header">
        <a href="{{ url('/admin/books') }}" class="davya-books-header__crumb">Books</a>
        <h1 class="davya-books-header__title">Activity history</h1>
    </div>

    <div class="davya-section-card">
        <div class="davya-section-card-title">Filters</div>
        <div style="display:flex; flex-wrap:wrap; align-items:end; gap:12px;">
            <div>
                <label style="display:block; font-size:var(--fs-11); color:var(--text-sub); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">Subject</label>
                <select wire:model.live="subjectTypeFilter" style="padding:6px 10px; border:1px solid var(--border); border-radius:var(--r-md); font-size:var(--fs-12); background:var(--surface); min-width:160px;">
                    <option value="">All subjects</option>
                    @foreach ($subjectTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block; font-size:var(--fs-11); color:var(--text-sub); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">Event</label>
                <select wire:model.live="eventFilter" style="padding:6px 10px; border:1px solid var(--border); border-radius:var(--r-md); font-size:var(--fs-12); background:var(--surface); min-width:120px;">
                    <option value="">All events</option>
                    @foreach ($events as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block; font-size:var(--fs-11); color:var(--text-sub); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">User</label>
                <select wire:model.live="causerIdFilter" style="padding:6px 10px; border:1px solid var(--border); border-radius:var(--r-md); font-size:var(--fs-12); background:var(--surface); min-width:200px;">
                    <option value="">All users</option>
                    @foreach ($causers as $id => $email)
                        <option value="{{ $id }}">{{ $email }}</option>
                    @endforeach
                </select>
            </div>
            @if ($subjectTypeFilter || $eventFilter || $causerIdFilter)
                <button wire:click="clearFilters" type="button" style="background:transparent; border:0; cursor:pointer; color:var(--brand-700); font-size:var(--fs-12); padding:6px 0;">Clear filters</button>
            @endif
        </div>
    </div>

    <div class="davya-section-card">
        <div class="davya-section-card-title">{{ $activities->total() }} {{ $activities->total() === 1 ? 'event' : 'events' }}</div>
        <div class="davya-table-scroll">
            <table class="davya-books-table">
                <thead><tr>
                    <th>When</th><th>User</th><th>Event</th><th>Subject</th><th>Changes</th>
                </tr></thead>
                <tbody>
                    @forelse ($activities as $a)
                        <tr>
                            <td style="white-space:nowrap; color:var(--text-sub); font-size:var(--fs-12);">{{ $a->created_at->format('d M Y H:i') }}</td>
                            <td style="font-size:var(--fs-12);">{{ $a->causer?->email ?? 'system' }}</td>
                            <td>
                                @php
                                    $variant = match($a->event) {
                                        'created' => 'success', 'updated' => 'info',
                                        'deleted' => 'danger', 'restored' => 'warning',
                                        default => '',
                                    };
                                @endphp
                                <span class="davya-books-badge davya-books-badge--{{ $variant }}">{{ $a->event }}</span>
                            </td>
                            <td>
                                <div class="title">{{ class_basename($a->subject_type) }} #{{ $a->subject_id }}</div>
                                @if ($a->subject)
                                    <div style="font-size:var(--fs-11); color:var(--text-muted);">{{ $a->subject->title ?? $a->subject->name ?? $a->subject->source ?? '—' }}</div>
                                @endif
                            </td>
                            <td style="font-size:var(--fs-11); color:var(--text-sub); max-width:380px;">
                                @if (! empty($a->properties['attributes'] ?? []))
                                    @foreach ($a->properties['attributes'] as $key => $val)
                                        @php
                                            $oldVal = data_get($a->properties, "old.$key");
                                        @endphp
                                        <div>
                                            <span style="font-weight:600; color:var(--text);">{{ $key }}:</span>
                                            @if ($a->event === 'updated' && $oldVal !== null && $oldVal !== $val)
                                                <span style="color:var(--danger); text-decoration:line-through;">{{ is_scalar($oldVal) ? $oldVal : json_encode($oldVal) }}</span>
                                                →
                                                <span style="color:var(--brand-700);">{{ is_scalar($val) ? $val : json_encode($val) }}</span>
                                            @else
                                                <span>{{ is_scalar($val) ? $val : json_encode($val) }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="text-align:center; padding:32px 0; color:var(--text-sub);">No activity yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px;">{{ $activities->links() }}</div>
    </div>
</x-filament-panels::page>
