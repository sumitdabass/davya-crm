@php
    $current = request()->path();
    $items = [
        ['url' => '/admin/rank-lookup', 'match' => 'admin/rank-lookup', 'label' => 'Lookup'],
        ['url' => '/admin/rank/cutoffs', 'match' => 'admin/rank/cutoffs', 'label' => 'Cutoffs'],
        ['url' => '/admin/rank/seats', 'match' => 'admin/rank/seats', 'label' => 'Seats'],
        ['url' => '/admin/rank/universities', 'match' => 'admin/rank/universities', 'label' => 'Universities'],
        ['url' => '/admin/rank/courses', 'match' => 'admin/rank/courses', 'label' => 'Courses'],
        ['url' => '/admin/rank/institutes', 'match' => 'admin/rank/institutes', 'label' => 'Institutes'],
        ['url' => '/admin/rank/branches', 'match' => 'admin/rank/branches', 'label' => 'Branches'],
        ['url' => '/admin/rank/qualifying-exams', 'match' => 'admin/rank/qualifying-exams', 'label' => 'Exams'],
        ['url' => '/admin/rank/admission-processes', 'match' => 'admin/rank/admission-processes', 'label' => 'Processes'],
    ];
@endphp

<nav class="rank-subnav no-print" style="display:flex; flex-wrap:wrap; gap:6px; margin:-8px 0 16px 0;">
    @foreach ($items as $i)
        @php $active = str_starts_with($current, $i['match']); @endphp
        <a href="{{ $i['url'] }}"
           style="text-decoration:none;
                  padding: 5px 10px;
                  border-radius: 6px;
                  font-size: 12px;
                  font-weight: 500;
                  border: 1px solid {{ $active ? '#059669' : '#e5e7eb' }};
                  background: {{ $active ? '#059669' : 'transparent' }};
                  color: {{ $active ? '#fff' : '#374151' }};">
            {{ $i['label'] }}
        </a>
    @endforeach
</nav>
