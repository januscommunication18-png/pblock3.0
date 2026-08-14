<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Drafts — {{ $workspace->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  {{-- The shared Vue runtime: PB.boot, the toast, <pb-modal>, <pb-confirm>, <pb-empty>,
       and <pb-combo> — the searchable combobox every picker on this screen uses. --}}
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  {{-- The description editor, from the shared partial — Jodit with a Quill fallback. --}}
  @include('partials.rich-editor')
  <link rel="stylesheet" href="{{ pb_asset('assets/css/work-items.css') }}" />
  {{-- The work item row vocabulary: WI_PRI's priority icons and wiStateIcon's state glyphs.
       A draft's priority must read the same as a work item's, and it does because it is the
       same markup — not a second set of dots that drifts. --}}
  <script defer src="{{ pb_asset('assets/js/projects/work-item-ui.js') }}"></script>
  {{-- <wi-calendar>: the one date control the work item chips and the Create Cycle form
       already mount, so a date is picked the same way here as everywhere else. Self-contained
       apart from wiIcon, which icons.js above provides — all defer, so the order holds. --}}
  <script defer src="{{ pb_asset('assets/js/projects/date-picker.js') }}"></script>
  {{-- work-items.js is where <wi-editor> is defined; work-item-list.js is its own dependency.
       Loaded for the component only — the Work Items screen's boot inside it is guarded by
       data-screen, so it does not mount over the drafts root. Same arrangement Pages uses. --}}
  <script defer src="{{ pb_asset('assets/js/projects/work-item-list.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/projects/work-items.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/drafts.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      <div id="settings-root" class="flex-1 min-h-0 flex flex-col" data-bootstrap='@json($bootstrap)'>
        <div class="px-6 py-10 text-sub text-[13px]">Loading drafts…</div>
      </div>
    </main>
  </div>
</body>
</html>
