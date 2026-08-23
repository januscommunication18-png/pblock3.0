@extends('help-center.layout')

@section('title', $request->ticketNumber().' — '.$space->name)

@section('content')
  {{-- One Request, as a full page (docs/features/help-center.md, P46).

       The drawer's Expand links here, the way a work item's Expand links to its own page. This
       is NOT a second rendering of the detail: it is the same Vue screen from inbox.js, put in
       page mode by `pageRequestId` in the bootstrap, which hides the grid and unwraps the drawer
       to fill the page. Every picker, tab, composer and dialog is the drawer's own. --}}

  {{-- NO toolbar of its own, unlike every other screen in the module — and that is the point.

       In page mode the drawer renders its own h-14 toolbar carrying a breadcrumb back to the
       Space's Inbox, exactly as the work item page does. A second bar above it would be two
       headings for one thing.

       The height is pinned rather than left to flow: the drawer's body is a two-column layout
       whose columns scroll independently, and that needs a bounded height to scroll inside.
       An inline style because Tailwind emits only what it finds in the sources it scans, and an
       arbitrary viewport height is not something it would find here. --}}
  <div style="height: 100vh">
    {{-- Mount only. PB.boot clears this root, so what is inside is the placeholder that shows
         while the deferred scripts load. --}}
    <div id="help-center-inbox" class="h-full" data-bootstrap="{{ json_encode($inboxQueue) }}">
      <p id="help-center-inbox-loading" class="px-5 sm:px-8 py-6 text-[13px] text-sub">Loading {{ $request->ticketNumber() }}&hellip;</p>
    </div>

    {{-- The same watchdog the queue screens carry, and for the same reason: the Request is
         already on the page, so this is never waiting on a request — if the placeholder is
         still here after eight seconds the screen script did not run, and saying so beats a
         line that reads as a slow network forever. --}}
    <script>
      setTimeout(function () {
        var stuck = document.getElementById('help-center-inbox-loading');
        if (!stuck) return;

        stuck.className = 'mx-5 sm:mx-8 my-6 max-w-[560px] rounded-lg border border-danger/30 bg-danger/5 px-4 py-3 text-[13px] text-ink';
        stuck.innerHTML = '<span class="font-semibold">This ticket could not be displayed.</span>' +
          '<span class="block mt-1 text-[12px] text-sub">It was loaded, but the screen failed to start. ' +
          'Reload the page; if it keeps happening, the browser console will say why.</span>' +
          '<button type="button" onclick="window.location.reload()" ' +
          'class="inline-flex items-center h-8 px-3 mt-2 rounded-md border border-stroke bg-white text-[13px] font-semibold text-ink hover:bg-hover">Reload</button>';
      }, 8000);
    </script>
  </div>

  @push('scripts')
    {{-- The grid's assets, in the one order that works — see the partial's own note. Tabulator
         comes with them and goes unused here, for the reason work-item-frame.blade.php gives:
         splitting the partial to save it would put a load order the screen depends on back in
         the hands of each page. --}}
    @include('partials.work-item-assets')
    <script defer src="{{ pb_asset('assets/js/help-center/inbox.js') }}"></script>
  @endpush
@endsection
