@props(['company' => null, 'fy' => null, 'title' => '', 'badge' => null])
<div class="davya-books-header">
    <a href="{{ url('/admin/books') }}" class="davya-books-header__crumb">Books</a>
    @if ($company)
        <a href="{{ url('/admin/books/'.$company->slug.($fy ? '/'.$fy->label : '')) }}" class="davya-books-header__crumb">{{ $company->name }}</a>
    @endif
    @if ($fy)
        <a href="{{ url('/admin/books/'.$company->slug.'/'.$fy->label) }}" class="davya-books-header__crumb">FY {{ $fy->label }}</a>
    @endif
    <h1 class="davya-books-header__title">{{ $title }}</h1>
    @if ($badge)
        {!! $badge !!}
    @endif
    @if ($fy?->is_closed && ! $badge)
        <span class="davya-books-badge davya-books-badge--warning">FY closed &mdash; read only</span>
    @endif
</div>
