<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>{{ $project->name }} — View</title>

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

  {{-- RevoGrid, vendored (see the vendor README for why the whole directory). A module, because
       it is a Stencil build that lazily imports its own chunks relative to this URL. Nothing is
       fetched at runtime from anywhere but this origin. --}}
  <script type="module" src="{{ pb_asset('assets/vendor/revogrid/revo-grid.esm.js') }}"></script>

  {{-- Loaded AFTER work-items.css: it themes RevoGrid to match the app. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/css/views.css') }}" />
  <script defer src="{{ pb_asset('assets/js/projects/view-grid.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/views.js') }}"></script>
</head>

{{-- The View IS the page. No topbar, no workspace rail, no sidebar, no project tab bar — the
     screen renders its own slim header with Back and Close, and everything below it is grid.

     Deliberately a separate host rather than a flag on projects/views.blade.php: the difference
     is entirely which chrome partials get included, and expressing that as @if around four
     includes is how a layout ends up with two half-states. The Vue screen is the same file and
     the same bootstrap; only `external` differs.

     This is also the shape §18.3 asks a published link to have, so Slice 3's public page starts
     from this layout instead of inventing one. --}}
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">
  <div id="settings-root" class="flex-1 min-h-0 flex flex-col" data-screen="views" data-bootstrap='@json($bootstrap)'>
    <div class="px-6 py-10 text-sub text-[13px]">Loading view…</div>
  </div>
</body>
</html>
