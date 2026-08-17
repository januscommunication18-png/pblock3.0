<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>{{ $collection->name }} — Wiki</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  {{-- Drag-and-drop for the page tree. Vendored, never a CDN — see the README beside it. --}}
  <script src="{{ pb_asset('assets/vendor/sortable/Sortable.min.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/wiki-collection.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex flex-1 min-h-0">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 overflow-y-auto">
      {{-- Full width, no centred measure: the toolbar's border has to run the width of the
           screen the way the Projects index's does. The body inside it keeps the measure. --}}
      <div id="wiki-collection-root" data-bootstrap="{{ json_encode($bootstrap) }}">
        <div class="px-5 sm:px-8 py-10 text-[13px] text-sub">Loading…</div>
      </div>
    </main>
  </div>
</body>
</html>
