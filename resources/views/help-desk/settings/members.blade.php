<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Members — Help Desk — {{ $workspace->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  {{-- Hybrid Vue-in-Blade (CLAUDE.md §14): the shell is Blade, the member list is Vue, and
       both come from the shared settings runtime so this screen reuses the same modal,
       confirm and toast the rest of the app uses rather than growing its own. --}}
  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/help-desk/members.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      {{-- Help Desk settings navigation. One section today; it is a row rather than a title so
           that Inboxes, Channels and the rest of Phase 2 have somewhere to land. --}}
      <div class="h-12 shrink-0 border-b border-line flex items-center gap-3 px-5 sm:px-8">
        <a href="{{ route('help-desk.index') }}" class="text-sub hover:text-ink" title="Back to Help Desk">
          {!! pb_icon('arrow-left', 16) !!}
        </a>
        <span class="text-[13px] text-sub">Help Desk</span>
        <span class="text-faint">/</span>
        <span class="text-[13px] font-medium text-ink">Settings</span>
        <nav class="ml-4 flex items-center gap-1">
          <span class="h-7 px-3 grid place-items-center rounded-md bg-sel text-brand text-[12px] font-medium">Members</span>
        </nav>
      </div>

      <div class="flex-1 min-h-0 overflow-y-auto">
        <div id="help-desk-members-root" data-bootstrap="{{ json_encode($bootstrap) }}">
          <div class="px-5 sm:px-8 py-10 text-[13px] text-sub">Loading…</div>
        </div>
      </div>
    </main>
  </div>

</body>
</html>
