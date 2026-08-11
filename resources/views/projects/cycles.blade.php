<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Cycles — {{ $project->name }}</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="{{ pb_asset('assets/js/tailwind.config.js') }}"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/work-items.css') }}" />

  {{-- Tabulator — the cycle's work item list is the SAME grid, skin and row chips as the
       project's work item list, so the two read alike (Cycles §7.2). --}}
  <link rel="stylesheet" href="https://unpkg.com/tabulator-tables@6.3.1/dist/css/tabulator.min.css" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/tabulator-skin.css') }}" />
  <script src="https://unpkg.com/tabulator-tables@6.3.1/dist/js/tabulator.min.js"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  {{-- Shared with the Work Items screen, and loaded before cycles.js uses them. --}}
  <script defer src="{{ pb_asset('assets/js/projects/work-item-ui.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/date-picker.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/work-item-list.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/cycles.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      @include('partials.project-tabs')

      <div id="settings-root" class="flex-1 min-h-0 flex flex-col" data-bootstrap='@json($bootstrap)'>
        <div class="px-6 py-10 text-sub text-[13px]">Loading cycles…</div>
      </div>
    </main>
  </div>
</body>
</html>
