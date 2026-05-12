@props(['trail' => [], 'title' => '', 'badge' => null])
{{--
  Generic breadcrumb header used outside the Books module.
  $trail is an array of ['label' => string, 'href' => ?string] hops.
  $title is the page H1.
  $badge is optional raw HTML rendered to the right of the title.

  Visual matches .davya-books-header on purpose — same Bigin-flavoured
  "subtle crumb chain · big title" pattern across the app.
--}}
<div class="davya-books-header">
    @foreach ($trail as $hop)
        @if (! empty($hop['href']))
            <a href="{{ $hop['href'] }}" class="davya-books-header__crumb">{{ $hop['label'] }}</a>
        @else
            <span class="davya-books-header__crumb" style="cursor:default;">{{ $hop['label'] }}</span>
        @endif
    @endforeach
    <h1 class="davya-books-header__title">{{ $title }}</h1>
    @if ($badge)
        {!! $badge !!}
    @endif
</div>
