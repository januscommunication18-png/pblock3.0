<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Work items — {{ $project->name }}</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="{{ pb_asset('assets/js/tailwind.config.js') }}"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />

  {{-- Tabulator — the same data grid + skin the Members listing and the work-items POC use. --}}
  <link rel="stylesheet" href="https://unpkg.com/tabulator-tables@6.3.1/dist/css/tabulator.min.css" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/tabulator-skin.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/work-items.css') }}" />
  <script src="https://unpkg.com/tabulator-tables@6.3.1/dist/js/tabulator.min.js"></script>

  {{-- Quill (snow theme) — the rich-text editor for descriptions, comments and updates.
       Loaded the same way Tabulator is; the toolbar and handlers are configured in
       projects/work-items.js and its output is sanitized server-side by
       App\Services\RichTextSanitizer. --}}
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" />
  <script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  {{-- Shared with the Cycles screen, and loaded before the screen script that uses them:
       the row vocabulary (state/priority icons, chips, avatars) and the date picker
       component the Start/Due chips mount. --}}
  <script defer src="{{ pb_asset('assets/js/projects/work-item-ui.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/date-picker.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/work-item-list.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/work-items.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      @include('partials.project-tabs')

      <div id="settings-root" class="flex-1 min-h-0 flex flex-col" data-bootstrap='@json($bootstrap)'>
        <div class="px-6 py-10 text-sub text-[13px]">Loading work items…</div>
      </div>
    </main>
  </div>
</body>
</html>
