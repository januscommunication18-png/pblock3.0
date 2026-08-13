<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>{{ $workItem->identifier }} — {{ $project->name }}</title>

  {{-- Every link inside this page navigates the TOP window, not this frame.
       The panel that embeds it is a slide-over, not a browser: the drawer's own breadcrumb
       back to the work item list, its attachments and its linked pages would otherwise load a
       whole second app inside a 60%-wide panel. One line here beats a `target` on each. --}}
  <base target="_top" />

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  {{-- Quill (snow theme) — the rich-text editor for descriptions, comments and updates. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/vendor/quill/quill.snow.css') }}" />
  <script src="{{ pb_asset('assets/vendor/quill/quill.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>

  {{-- The same assets the full screen loads. Tabulator comes with them and goes unused here —
       the list is never rendered in this mode — but splitting the partial to save it would put
       the load order that <wi-list> depends on back in the hands of each page, which is the
       exact mistake work-item-assets.blade.php exists to prevent. --}}
  @include('partials.work-item-assets')

  <script defer src="{{ pb_asset('assets/js/projects/work-items.js') }}"></script>
</head>

{{-- The work item detail, and nothing else: no topbar, no workspace rail, no sidebar, no
     project tab bar. Same Vue screen and same bootstrap as projects/work-items.blade.php —
     `pageItemId` puts it in `pageMode`, which renders the drawer as the whole page — so the
     detail here is not a copy of the drawer, it IS the drawer.

     A separate host rather than a flag on work-items.blade.php, for the reason
     views-external.blade.php gives: the difference is entirely which chrome partials are
     included, and expressing that as @if around four includes is how a layout ends up with
     two half-states. --}}
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">
  <div id="settings-root" data-screen="work-items" class="flex-1 min-h-0 flex flex-col" data-bootstrap='@json($bootstrap)'>
    <div class="px-6 py-10 text-sub text-[13px]">Loading…</div>
  </div>
</body>
</html>
