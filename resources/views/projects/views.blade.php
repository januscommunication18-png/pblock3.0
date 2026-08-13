<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Views — {{ $project->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>

  {{-- The chip vocabulary — state and priority icons, avatars, label pills — from the same
       helpers the Work Items list renders with, so a status means the same thing in both
       places. Only the chips: the Views grid is RevoGrid, so Tabulator is not loaded here. --}}
  @include('partials.work-item-chips')

  {{-- DataTables (v3, MIT, vendored — see the vendor README). Dependency-free: jQuery was only
       required before v3. A plain script, not a module: it exposes `window.DataTable`. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/vendor/datatables/datatables.min.css') }}" />
  <script src="{{ pb_asset('assets/vendor/datatables/datatables.min.js') }}"></script>

  {{-- AFTER datatables.min.css, so the grid skin wins on order rather than on specificity.
       Loading these the other way round is the same trap that turned the Cycles group rows
       grey, and a test asserts this order. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/css/views.css') }}" />
  <script defer src="{{ pb_asset('assets/js/projects/view-grid.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/views.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      @include('partials.project-tabs')

      <div id="settings-root" class="flex-1 min-h-0 flex flex-col" data-screen="views" data-bootstrap='@json($bootstrap)'>
        <div class="px-6 py-10 text-sub text-[13px]">Loading views…</div>
      </div>
    </main>
  </div>
</body>
</html>
