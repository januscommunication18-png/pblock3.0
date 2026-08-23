@extends('help-center.layout')

@section('title', $viewLabel)

@section('content')
  {{-- The Help Center's own queue (docs/features/help-center.md, P21).

       One screen behind seven URLs — /help-center/inbox and /inbox/{view} — showing Requests
       from EVERY active Space. Which view is lit lives in the sidebar, not here: the queues are
       navigation now, so this page carries a heading and a grid and nothing that navigates. --}}

  {{-- Toolbar — the same h-12 bordered bar every screen in the module carries. --}}
  {{-- `shrink-0` — see the note in help-center/space.blade.php (P81). This bar is a flex child
       of a scrolling column, and without it a tall page compresses `h-12` and pushes the buttons
       onto the border. --}}
  <div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line shrink-0">
    @include('partials.sidebar-expand')
    <span class="flex items-center gap-2 text-[14px] font-medium text-ink min-w-0">
      {!! pb_icon('inbox', 16, 'text-sub shrink-0') !!}
      <span class="truncate">{{ $viewLabel }}</span>
    </span>
    <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
      {{-- The Spam shortcut that used to sit here is GONE (P47).

           P21 kept Spam out of the navigation and put a button here instead. The requirement now
           places Spam in the bar, after Snoozed — so this button became a second way to reach a
           screen the sidebar already links, sitting next to it on every queue page. One route in
           is what makes a navigation legible. --}}
      <a href="{{ route('help-center.spaces.index') }}"
         class="inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap">
        All Spaces
      </a>
    </div>
  </div>

  <div class="px-5 sm:px-8 py-6">
    {{-- Mount only. PB.boot clears this root, so what is inside is the placeholder that shows
         while the deferred grid scripts load — the screen itself is in inbox.js, shared with a
         Space's own Inbox. --}}
    <div id="help-center-inbox" data-bootstrap="{{ json_encode($bootstrap) }}">
      <p id="help-center-inbox-loading" class="text-[13px] text-sub">Loading Requests&hellip;</p>
    </div>

    {{-- The same watchdog a Space's Inbox carries, for the same reason: the rows are already on
         the page, so this is never waiting on a request — if the placeholder is still here
         after eight seconds, the screen script did not run and saying so is better than a line
         that reads as a slow network forever. --}}
    <script>
      setTimeout(function () {
        var stuck = document.getElementById('help-center-inbox-loading');
        if (!stuck) return;

        stuck.className = 'max-w-[560px] rounded-lg border border-danger/30 bg-danger/5 px-4 py-3 text-[13px] text-ink';
        stuck.innerHTML = '<span class="font-semibold">The queue could not be displayed.</span>' +
          '<span class="block mt-1 text-[12px] text-sub">The Requests were loaded, but the screen failed to start. ' +
          'Reload the page; if it keeps happening, the browser console will say why.</span>' +
          '<button type="button" onclick="window.location.reload()" ' +
          'class="inline-flex items-center h-8 px-3 mt-2 rounded-md border border-stroke bg-white text-[13px] font-semibold text-ink hover:bg-hover">Reload</button>';
      }, 8000);
    </script>
  </div>

  @push('scripts')
    {{-- The grid's assets, in the one order that works — see the partial's own note. --}}
    @include('partials.work-item-assets')
    <script defer src="{{ pb_asset('assets/js/help-center/inbox.js') }}"></script>
  @endpush
@endsection
