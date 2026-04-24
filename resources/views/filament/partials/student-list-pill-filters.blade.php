{{-- Custom pill filter bar for /admin/students, matching kanban-board sub-toolbar visual exactly.
     Binds to Filament's table filter state via wire:model.live="tableFilters.<name>.value"
     (SelectFilter) or .isActive (boolean Filter). Filament's updatedTableFilters() hook
     handles the query refresh automatically.
--}}
@php
    $pillSelect = 'appearance:none; padding: 4px 26px 4px 10px; font-size: var(--fs-11); font-weight:500; border-radius: var(--r-pill); border:1px solid var(--border); background: var(--surface); color: var(--text); cursor:pointer; background-image: linear-gradient(45deg, transparent 50%, var(--text-sub) 50%), linear-gradient(135deg, var(--text-sub) 50%, transparent 50%); background-position: calc(100% - 14px) 50%, calc(100% - 10px) 50%; background-size: 4px 4px, 4px 4px; background-repeat: no-repeat;';
    $pillSelectActive = str_replace('background: var(--surface); color: var(--text);', 'background: var(--brand-50); color: var(--brand-700); border-color: var(--brand-100); font-weight:600;', $pillSelect);

    $filters = $this->tableFilters ?? [];
    $isActive = fn (string $key) => filled($filters[$key]['value'] ?? null);
    $isBoolActive = fn (string $key) => (bool) ($filters[$key]['isActive'] ?? false);
    $anyActive = collect(['owner_id','stage','plan','course','current_round','lead_source','category','student_response'])
        ->contains(fn ($k) => $isActive($k))
        || collect(['has_pending','stuck','seat_fee_pending','re_entry'])->contains(fn ($k) => $isBoolActive($k));

    $owners = \App\Models\User::query()->orderBy('name')->pluck('name', 'id')->all();
    $courses = \App\Models\Student::query()->whereNotNull('course')->where('course','!=','')->distinct()->orderBy('course')->pluck('course','course')->all();
    $rounds = \App\Models\Student::query()->whereNotNull('current_round')->where('current_round','!=','')->distinct()->orderBy('current_round')->pluck('current_round','current_round')->all();
    $sources = \App\Models\Student::query()->whereNotNull('lead_source')->where('lead_source','!=','')->distinct()->orderBy('lead_source')->pluck('lead_source','lead_source')->all();
    $stages = collect(app(\App\Services\Pipeline\PipelineConfig::class)->stageNames())->mapWithKeys(fn ($n) => [$n => $n])->all();
    $plans = ['Online' => 'Online', 'Offline' => 'Offline', 'All' => 'All'];
    $categories = ['Delhi' => 'Delhi', 'Outside' => 'Outside'];
    $responses = ['Ready' => 'Ready', 'Not Interested' => 'Not Interested', 'Needs Time' => 'Needs Time'];
@endphp

<div class="davya-subtoolbar" style="background: var(--surface); border:1px solid var(--border); border-radius: var(--r-md); padding: 8px 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: var(--s-3);">
    <select wire:model.live="tableFilters.owner_id.value" aria-label="Filter by owner"
            style="{{ $isActive('owner_id') ? $pillSelectActive : $pillSelect }}">
        <option value="">Owner · Anyone</option>
        @foreach ($owners as $id => $name)
            <option value="{{ $id }}">Owner: {{ $name }}</option>
        @endforeach
    </select>

    <select wire:model.live="tableFilters.stage.value" aria-label="Filter by stage"
            style="{{ $isActive('stage') ? $pillSelectActive : $pillSelect }}">
        <option value="">Stage · All</option>
        @foreach ($stages as $value => $label)
            <option value="{{ $value }}">Stage: {{ $label }}</option>
        @endforeach
    </select>

    <select wire:model.live="tableFilters.course.value" aria-label="Filter by course"
            style="{{ $isActive('course') ? $pillSelectActive : $pillSelect }}">
        <option value="">Course · All</option>
        @foreach ($courses as $value => $label)
            <option value="{{ $value }}">Course: {{ $label }}</option>
        @endforeach
    </select>

    <select wire:model.live="tableFilters.current_round.value" aria-label="Filter by round"
            style="{{ $isActive('current_round') ? $pillSelectActive : $pillSelect }}">
        <option value="">Round · Any</option>
        @foreach ($rounds as $value => $label)
            <option value="{{ $value }}">Round: {{ $label }}</option>
        @endforeach
    </select>

    <select wire:model.live="tableFilters.lead_source.value" aria-label="Filter by lead source"
            style="{{ $isActive('lead_source') ? $pillSelectActive : $pillSelect }}">
        <option value="">Source · All</option>
        @foreach ($sources as $value => $label)
            <option value="{{ $value }}">Source: {{ $label }}</option>
        @endforeach
    </select>

    <select wire:model.live="tableFilters.plan.value" aria-label="Filter by plan"
            style="{{ $isActive('plan') ? $pillSelectActive : $pillSelect }}">
        <option value="">Plan · All</option>
        @foreach ($plans as $value => $label)
            <option value="{{ $value }}">Plan: {{ $label }}</option>
        @endforeach
    </select>

    <select wire:model.live="tableFilters.category.value" aria-label="Filter by category"
            style="{{ $isActive('category') ? $pillSelectActive : $pillSelect }}">
        <option value="">Category · All</option>
        @foreach ($categories as $value => $label)
            <option value="{{ $value }}">Category: {{ $label }}</option>
        @endforeach
    </select>

    <select wire:model.live="tableFilters.student_response.value" aria-label="Filter by response"
            style="{{ $isActive('student_response') ? $pillSelectActive : $pillSelect }}">
        <option value="">Response · All</option>
        @foreach ($responses as $value => $label)
            <option value="{{ $value }}">Response: {{ $label }}</option>
        @endforeach
    </select>

    <button type="button" wire:click="$toggle('tableFilters.has_pending.isActive')"
            style="font-size: var(--fs-11); font-weight: {{ $isBoolActive('has_pending') ? '600' : '500' }}; padding: 4px 10px; border-radius: var(--r-pill); border: 1px solid {{ $isBoolActive('has_pending') ? 'var(--brand-100)' : 'var(--border)' }}; background: {{ $isBoolActive('has_pending') ? 'var(--brand-50)' : 'var(--surface)' }}; color: {{ $isBoolActive('has_pending') ? 'var(--brand-700)' : 'var(--text)' }}; cursor: pointer;">
        Pending amount
    </button>

    <button type="button" wire:click="$toggle('tableFilters.stuck.isActive')"
            style="font-size: var(--fs-11); font-weight: {{ $isBoolActive('stuck') ? '600' : '500' }}; padding: 4px 10px; border-radius: var(--r-pill); border: 1px solid {{ $isBoolActive('stuck') ? 'var(--brand-100)' : 'var(--border)' }}; background: {{ $isBoolActive('stuck') ? 'var(--brand-50)' : 'var(--surface)' }}; color: {{ $isBoolActive('stuck') ? 'var(--brand-700)' : 'var(--text)' }}; cursor: pointer;">
        Stuck 14+d
    </button>

    <button type="button" wire:click="$toggle('tableFilters.seat_fee_pending.isActive')"
            style="font-size: var(--fs-11); font-weight: {{ $isBoolActive('seat_fee_pending') ? '600' : '500' }}; padding: 4px 10px; border-radius: var(--r-pill); border: 1px solid {{ $isBoolActive('seat_fee_pending') ? 'var(--brand-100)' : 'var(--border)' }}; background: {{ $isBoolActive('seat_fee_pending') ? 'var(--brand-50)' : 'var(--surface)' }}; color: {{ $isBoolActive('seat_fee_pending') ? 'var(--brand-700)' : 'var(--text)' }}; cursor: pointer;">
        Seat fee pending
    </button>

    <button type="button" wire:click="$toggle('tableFilters.re_entry.isActive')"
            style="font-size: var(--fs-11); font-weight: {{ $isBoolActive('re_entry') ? '600' : '500' }}; padding: 4px 10px; border-radius: var(--r-pill); border: 1px solid {{ $isBoolActive('re_entry') ? 'var(--brand-100)' : 'var(--border)' }}; background: {{ $isBoolActive('re_entry') ? 'var(--brand-50)' : 'var(--surface)' }}; color: {{ $isBoolActive('re_entry') ? 'var(--brand-700)' : 'var(--text)' }}; cursor: pointer;">
        Re-entry
    </button>

    @if ($anyActive)
        <button type="button" wire:click="resetTableFiltersForm"
                style="font-size: var(--fs-11); color: var(--text-sub); background:transparent; border:0; cursor:pointer; text-decoration:underline;">
            Clear filters
        </button>
    @endif
</div>
