<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>{{ $page->title }} — {{ $collection->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>

  {{-- The SAME editor Project Pages uses, from the shared partial. "Teams shouldn't need to
       learn another editor" is the requirement; loading a second one here would have made a
       liar of it. --}}
  @include('partials.rich-editor')

  {{-- work-items.css carries the rich-text read styles the body renders with; pages.css strips
       the editor's field chrome so it reads as a document. Order is load-bearing — the same
       order projects/pages.blade.php loads them in. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/css/work-items.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/pages.css') }}" />

  <script defer src="{{ pb_asset('assets/js/wiki-page.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      <div id="wiki-page-root" class="flex-1 min-h-0 flex flex-col" data-bootstrap='@json($bootstrap)'>
        <div class="p-8 text-[13px] text-sub">Loading…</div>
      </div>
    </main>
  </div>
</body>
</html>
