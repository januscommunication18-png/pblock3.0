<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Activity — Help Desk — {{ $workspace->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      <div class="h-12 shrink-0 border-b border-line flex items-center gap-3 px-5 sm:px-8">
        <a href="{{ route('help-desk.index') }}" class="text-sub hover:text-ink" title="Back to Help Desk">
          {!! pb_icon('arrow-left', 16) !!}
        </a>
        <span class="text-[13px] text-sub">Help Desk</span>
        <span class="text-faint">/</span>
        <span class="text-[13px] font-medium text-ink">Settings</span>
        <nav class="ml-4 flex items-center gap-1">
          <a href="{{ route('help-desk.inboxes') }}" class="h-7 px-3 grid place-items-center rounded-md text-sub hover:bg-hover text-[12px]">Inboxes</a>
          <a href="{{ route('help-desk.members') }}" class="h-7 px-3 grid place-items-center rounded-md text-sub hover:bg-hover text-[12px]">Members</a>
          <span class="h-7 px-3 grid place-items-center rounded-md bg-sel text-brand text-[12px] font-medium">Activity</span>
        </nav>
      </div>

      {{-- Server-rendered, deliberately: this is a read-only list with a pager and nothing to
           interact with, so a Vue component would be a runtime dependency for prose. --}}
      <div class="flex-1 min-h-0 overflow-y-auto">
        <div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">
          <div class="mb-5">
            <h1 class="text-[20px] font-bold text-head">Activity</h1>
            <p class="text-[13px] text-sub mt-1 max-w-2xl">
              Who changed what in {{ $helpDesk->name }} — membership, roles, inbox access and
              invitations. Names are recorded as they read at the time.
            </p>
          </div>

          @forelse ($entries as $entry)
            <div class="flex gap-3 py-3 border-b border-line last:border-0">
              <span class="h-7 w-7 rounded-full grid place-items-center text-white text-[11px] font-semibold shrink-0 mt-0.5"
                    style="background: {{ $entry['color'] }}">{{ $entry['initial'] }}</span>
              <div class="min-w-0 flex-1">
                <p class="text-[13px] text-ink">
                  <span class="font-medium">{{ $entry['actor'] }}</span>
                  {{ $entry['sentence'] }}
                </p>
                <p class="text-[12px] text-faint mt-0.5">{{ $entry['at'] }}</p>
              </div>
            </div>
          @empty
            <div class="border border-dashed border-stroke rounded-xl px-6 py-10 text-center">
              <div class="text-[14px] font-medium text-head">Nothing has happened yet</div>
              <p class="text-[13px] text-sub mt-1">Changes to members, roles, inbox access and invitations appear here.</p>
            </div>
          @endforelse

          {{-- Newer/Older rather than a numbered pager: an activity stream is read by walking
               back through it, and page numbers in a list that grows from the top point at
               different entries tomorrow. --}}
          @if ($entries->hasPages())
            <div class="mt-6 flex items-center justify-between">
              @if ($entries->previousPageUrl())
                <a href="{{ $entries->previousPageUrl() }}"
                   class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Newer</a>
              @else
                <span></span>
              @endif
              @if ($entries->nextPageUrl())
                <a href="{{ $entries->nextPageUrl() }}"
                   class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">Older</a>
              @endif
            </div>
          @endif
        </div>
      </div>
    </main>
  </div>

</body>
</html>
