<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Modules — {{ $project->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>

  {{-- A module's work items are the SAME grid as the project's work item list. --}}
  @include('partials.work-item-assets')

  {{-- The detail's work item grid mounts the WORK ITEMS SCREEN itself, not a read-only
       copy of it — so chips are editable and a row opens the same drawer. This file
       defines the component; its own boot is guarded by data-screen. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/vendor/quill/quill.snow.css') }}" />
  <script src="{{ pb_asset('assets/vendor/quill/quill.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/work-items.js') }}"></script>

  <script defer src="{{ pb_asset('assets/js/projects/modules.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      @include('partials.project-tabs')

      <div id="settings-root" class="flex-1 min-h-0 flex flex-col" data-bootstrap='@json($bootstrap)'>
        <div class="px-6 py-10 text-sub text-[13px]">Loading modules…</div>
      </div>
    </main>
  </div>
</body>
</html>
