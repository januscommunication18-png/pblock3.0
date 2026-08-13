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
  {{-- The description editor. <pg-editor> (Jodit) is the one the Pages screen uses, and the
       one this screen prefers: its template is a bare <textarea> with no reactive bindings, so
       Vue renders it once and never patches the DOM the editor owns — which is what Quill
       could not survive here (see drafts.md, "Two rules for driving the editor").
       Conditional exactly as Pages has it, so a checkout without the licensed package still
       gets an editor. --}}
  @if (file_exists(public_path('assets/vendor/jodit/jodit.fat.min.js')))
    <link rel="stylesheet" href="{{ pb_asset('assets/vendor/jodit/jodit.fat.min.css') }}" />
    <script src="{{ pb_asset('assets/vendor/jodit/jodit.fat.min.js') }}"></script>
  @endif
  {{-- Quill and <wi-editor> stay as the fallback for that case, on the same terms Pages keeps
       them. work-items.css also carries the rich-text read styles the list excerpts render
       with, so it is loaded either way. --}}
  <link rel="stylesheet" href="{{ pb_asset('assets/vendor/quill/quill.snow.css') }}" />
  <script src="{{ pb_asset('assets/vendor/quill/quill.js') }}"></script>
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
  {{-- <pg-editor> + pgJoditReady(), which decides which of the two the screen mounts. --}}
  <script defer src="{{ pb_asset('assets/js/projects/page-editor.js') }}"></script>
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
