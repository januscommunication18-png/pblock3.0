<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Your work — {{ $workspace->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  {{-- Quill, for the descriptions, comments and updates the detail drawer edits — the same
       reason the Work Items screen loads it, because this mounts that screen. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/vendor/quill/quill.snow.css') }}" />
  <script src="{{ pb_asset('assets/vendor/quill/quill.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>

  {{-- The work item grid and everything it draws with. --}}
  @include('partials.work-item-assets')

  {{-- work-items.js defines <work-items-screen>; its own boot is guarded by data-screen, so
       it does not mount over this root. Same arrangement the Epic screen uses. --}}
  <script defer src="{{ pb_asset('assets/js/projects/work-items.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/your-work.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      <div id="settings-root" data-screen="your-work" class="flex-1 min-h-0 flex flex-col" data-bootstrap='@json($bootstrap)'>
        <div class="px-6 py-10 text-sub text-[13px]">Loading your work…</div>
      </div>
    </main>
  </div>
</body>
</html>
