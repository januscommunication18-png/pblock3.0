<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Pages — {{ $project->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>

  {{-- §10's editor is the one work item descriptions already use — same formatting, same
       paste handling, same server-side sanitizing. work-items.js defines <wi-editor>; its own
       boot is guarded by data-screen, so it does not mount over the pages root.
       work-items.css carries the rich-text read styles the page body renders with. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/vendor/quill/quill.snow.css') }}" />
  <script src="{{ pb_asset('assets/vendor/quill/quill.js') }}"></script>
  <link rel="stylesheet" href="{{ pb_asset('assets/css/work-items.css') }}" />
  {{-- Loaded AFTER work-items.css and Quill's own: it strips Snow's field chrome so the
       editor reads as a document. Order is load-bearing, as it is for the grid skin. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/css/pages.css') }}" />
  <script defer src="{{ pb_asset('assets/js/projects/work-item-ui.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/date-picker.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/work-item-list.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/work-items.js') }}"></script>

  {{-- Jodit (Pro) — the Pages editor.
       The FAT build, deliberately: the slim one keeps its 25 Pro plugins as separate files
       loaded at runtime from a relative `plugins/` path, which does not exist once the build
       is vendored — so every Pro feature silently failed to appear. The fat build inlines
       them, which also satisfies this project's no-CDN rule: nothing is fetched at runtime.
       Still conditional, so a checkout without the licensed package falls back to Quill. --}}
  @if (file_exists(public_path('assets/vendor/jodit/jodit.fat.min.js')))
    <link rel="stylesheet" href="{{ pb_asset('assets/vendor/jodit/jodit.fat.min.css') }}" />
    <script src="{{ pb_asset('assets/vendor/jodit/jodit.fat.min.js') }}"></script>
  @endif
  <script defer src="{{ pb_asset('assets/js/projects/page-editor.js') }}"></script>

  <script defer src="{{ pb_asset('assets/js/projects/pages.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      @include('partials.project-tabs')

      <div id="settings-root" class="flex-1 min-h-0 flex flex-col" data-bootstrap='@json($bootstrap)'>
        <div class="px-6 py-10 text-sub text-[13px]">Loading pages…</div>
      </div>
    </main>
  </div>
</body>
</html>
