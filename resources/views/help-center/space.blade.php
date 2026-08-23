@extends('help-center.layout')

@section('title', $space->name.' — '.$panelLabel)

@section('content')
  {{-- One Space, one section (P4).

       Every panel below reads through `$space`, never through the models' own tables — so a
       Space's screen structurally cannot show another Space's Inbox or Requests
       (P3 §15). The relations are eager-loaded once by the controller. --}}
  {{-- Toolbar — the same h-12 bordered bar the Spaces listing and the Projects index use, so
       every screen in the module carries one heading style: 14px medium, leading icon,
       right-aligned actions. It is flush to the edges, which is why the page's own padding
       starts below it rather than wrapping it. --}}
  {{-- `shrink-0` is load-bearing, not decoration (P81).

       This bar is a flex child of the layout's `<main class="… flex flex-col">`, which is also
       the scroll container. A flex item defaults to `flex-shrink: 1`, so when the column's
       content is taller than the container — the Overview, with its charts and tables — the
       browser takes the overflow out of every child that will give, and `h-12` becomes a
       SUGGESTION. The bar rendered at 33px against the Inbox's 48px, and the 32px buttons in it
       ended up with 0px above and 1px below: sitting on the border, which is what was reported.

       Inbox looked right only by luck. Its list carries its own height and its own scroll, so
       that column happened to fit and nothing was asked to shrink. --}}
  <div class="flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line shrink-0">
    @include('partials.sidebar-expand')
    <span class="flex items-center gap-2 text-[14px] font-medium text-ink min-w-0">
      {!! pb_icon('rectangles-pair', 16, 'text-sub shrink-0') !!}
      <span class="truncate">{{ $space->name }}</span>
    </span>
    @if ($space->archived_at)
      <span class="_moretogether-badge _moretogether-badge--off shrink-0">Archived</span>
    @endif
    <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
      @if ($spaceEdit['can'])
        {{-- Sibling of the dialog's Vue root, bound by id — the same pattern the sidebar's
             create actions use, so the header does not have to know the dialog exists. --}}
        <button type="button" id="help-center-edit-space"
                class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap">
          {!! pb_icon('pen', 13) !!} Edit Space
        </button>
      @endif
      <a href="{{ route('help-center.spaces.index') }}"
         class="inline-flex items-center h-8 px-3 rounded-md border border-stroke text-[13px] text-ink hover:bg-hover whitespace-nowrap">
        All Spaces
      </a>
    </div>

    {{-- The dialog's root. Teleports to <body>, so it only has to exist. --}}
    @if ($spaceEdit['can'])
      <div id="help-center-space-edit" data-bootstrap="{{ json_encode($spaceEdit) }}"></div>
      @push('scripts')
        <script defer src="{{ pb_asset('assets/js/help-center/space-edit.js') }}"></script>
      @endpush
    @endif
  </div>

  {{-- The same sections as the sidebar, as tabs — so a Space can be moved through without
       going back to the nav. Both are built from `$sections`, which is why "active" means the
       same in both. Its own bar under the toolbar, matching the toolbar's flush edges. --}}
  @include('partials.help-center-space-tabs')

  <div class="px-5 sm:px-8 py-6">

    {{-- The Space's types and description used to sit here, above every panel.

         They are facts ABOUT the Space, and they were being printed over the top of the screens
         you go to a Space to work in — a queue, or one of its views. On the Inbox that meant a
         Space's type chip and its description standing between the tab bar and the Requests,
         answering a question nobody had while the list they came for was pushed down.

         They live on the Overview now, in the table that already answers "how is this Space set
         up?" — Space Type was duplicated there anyway, and Description has joined it. --}}

    {{-- ============ Overview ============ --}}
    @if ($panel === 'overview')
      @php($inbox = $space->inboxes->first())

      {{-- The reporting dashboard (docs/features/help-center.md, P50).

           FIRST on the Overview, because the requirement makes this page "the primary reporting
           dashboard for each Help Desk Space" — the configuration summary below it is reference
           material, and reference material does not lead a dashboard. --}}
      <div id="help-center-overview" class="mt-6"
           data-bootstrap="{{ json_encode(['report' => $report, 'options' => $reportOptions]) }}">
        <p id="help-center-overview-loading" class="text-[13px] text-sub">Loading reports&hellip;</p>
      </div>

      {{-- The same watchdog every deferred screen in this module carries: the data is already on
           the page, so this is never waiting on a request — if the placeholder is still here
           after eight seconds the screen script did not run, and saying so beats a line that
           reads as a slow network for ever. --}}
      <script>
        setTimeout(function () {
          var stuck = document.getElementById('help-center-overview-loading');
          if (!stuck) return;

          stuck.className = 'max-w-[560px] rounded-lg border border-danger/30 bg-danger/5 px-4 py-3 text-[13px] text-ink';
          stuck.innerHTML = '<span class="font-semibold">The reports could not be displayed.</span>' +
            '<span class="block mt-1 text-[12px] text-sub">The data was loaded, but the screen failed to start. ' +
            'Reload the page; if it keeps happening, the browser console will say why.</span>';
        }, 8000);
      </script>

      @push('scripts')
        <script defer src="{{ pb_asset('assets/js/help-center/overview.js') }}"></script>
      @endpush

      {{-- The Space configuration table that used to sit here has MOVED (P51).

           It is now a dialog behind the "Space Configuration" button in the dashboard header —
           see public/assets/js/help-center/overview.js. It was reference material sitting below
           eight panels of numbers, which is neither findable when you want it nor out of the way
           when you do not.

           The two blocks below stay on the page on purpose: they are not configuration, they are
           things to ACT on. A missing customer-facing address is a fault to fix, and the inbound
           test is a thing to run. --}}

      {{-- The gap that actually stops the inbound test working, called out where it is noticed
           rather than left as a silent zero. --}}
      @if ($inbox && $inbox->emailAddresses->isEmpty())
        <div class="mt-6 max-w-[720px] _moretogether-notice px-4 py-3">
          <div class="text-[13px] font-semibold text-ink">No customer-facing email address</div>
          <p class="mt-1 text-[12px] text-sub">
            This Inbox has no address for customers to write to, so there is nothing to forward
            from and the inbound test cannot run. Add the address your forwarding rule sits on.
          </p>
          <a href="{{ route('help-center.inboxes') }}"
             class="inline-flex items-center h-8 px-3 mt-2 rounded-md border border-stroke bg-white text-[13px] font-semibold text-ink hover:bg-hover">
            Add email address
          </a>
        </div>
      @endif

      {{-- Inbound email testing (P7). Only where there is an Inbox to test through. --}}
      @if ($inbox)
        <div id="help-center-inbound-test" data-bootstrap="{{ json_encode($inboundTest) }}">
          <div class="mt-6 max-w-[720px] rounded-lg border border-line px-4 py-6 text-[13px] text-sub">Loading…</div>
        </div>
        @push('scripts')
          <script defer src="{{ pb_asset('assets/js/help-center/inbound-test.js') }}"></script>
        @endpush
      @endif

    {{-- ============ Inbox, and its views (P9, P22) ============ --}}
    @elseif ($inboxQueue)
      {{-- The Inbox is the module's ONLY queue (P9). Every inbound email is a Request with its
           own Ticket Number, and this is where one is worked.

           The same mount serves Unassigned / Mine / Draft / Assigned / Closed / Spam (P22).
           They are the SAME screen with the view applied in SQL, so they share this root, this
           script and this watchdog rather than each getting a copy of them.

           It is the WORK ITEM GRID: same Tabulator table, same .wi-grid skin, same group
           headers. A Request and a work item are both "a thing with a status and an owner".

           The filters are generated from the SPACE'S OWN WORKFLOW — no status name appears in
           this file or in the screen script, which is what lets two Spaces run entirely
           different workflows with no code between them. --}}
      {{-- Mount only. PB.boot clears this root, so what is inside is the placeholder that
           shows while the deferred grid scripts load — the screen is in inbox.js. --}}
      <div id="help-center-inbox" data-bootstrap="{{ json_encode($inboxQueue) }}">
        <p id="help-center-inbox-loading" class="mt-6 text-[13px] text-sub">Loading Requests&hellip;</p>
      </div>

      {{-- The watchdog.

           "Loading Requests…" is the placeholder Vue replaces on mount. If the screen script
           never runs — a 404 on the asset, a blocked CDN, a JS error, a mount point that does
           not resolve — nothing replaces it, and the page sits on that line indefinitely with
           no way for the person reading it to tell a slow network from a broken build.

           INLINE and not in inbox.js on purpose: the failures worth catching here include
           "inbox.js did not load", and a guard inside the file it is guarding cannot fire.

           The rows are already on the page in `data-bootstrap`, so this is never waiting on a
           request — eight seconds is generous for parsing and mounting what is already here. --}}
      <script>
        setTimeout(function () {
          var stuck = document.getElementById('help-center-inbox-loading');
          if (!stuck) return;

          stuck.className = 'mt-6 max-w-[560px] rounded-lg border border-danger/30 bg-danger/5 px-4 py-3 text-[13px] text-ink';
          stuck.innerHTML = '<span class="font-semibold">The Inbox could not be displayed.</span>' +
            '<span class="block mt-1 text-[12px] text-sub">The Requests were loaded, but the screen failed to start. ' +
            'Reload the page; if it keeps happening, the browser console will say why.</span>' +
            '<button type="button" onclick="window.location.reload()" ' +
            'class="inline-flex items-center h-8 px-3 mt-2 rounded-md border border-stroke bg-white text-[13px] font-semibold text-ink hover:bg-hover">Reload</button>';
        }, 8000);
      </script>
      @push('scripts')
        {{-- The grid's assets, in the one order that works — see the partial's own note. --}}
        @include('partials.work-item-assets')
        <script defer src="{{ pb_asset('assets/js/help-center/inbox.js') }}"></script>
      @endpush

    @endif

  </div>
@endsection
