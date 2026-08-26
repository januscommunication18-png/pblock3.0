{{-- Workspace switcher modal (spec §7).

     Included once by the shared topbar, so the switcher is available from every screen
     rather than only from /welcome — anything on the page opens it by carrying
     `data-ws-open`, and the modal closes on the backdrop, the X, or Escape.

     Rows are rendered server-side: switching is a POST (it changes state), so each row is a
     real form that works without JavaScript. `$switcherGroups` is supplied by the view
     composer in AppServiceProvider, which is why no controller has to pass it. --}}
@php
  // My Workspaces / Invited Workspaces (docs/features/tenant-workspace-ownership.md §12).
  // Supplied already grouped, and a group with nothing in it never arrives — so a person who
  // has only ever been invited sees one heading, not an empty "My Workspaces".
  $switcherGroups = $switcherGroups ?? [];
  // Stable per-row avatar colours; the workspace itself has no colour of its own yet.
  $wsColors = ['#334155', '#1b5f8a', '#7c3aed', '#0891b2', '#be123c', '#15803d', '#b45309', '#4338ca'];
  // Counted across the groups, not per group, so the same workspace keeps its colour wherever
  // it is listed.
  $wsIndex = 0;
@endphp

<div id="ws-modal" class="hidden fixed inset-0 z-[60] flex items-start justify-center p-4 sm:pt-24"
     role="dialog" aria-modal="true" aria-labelledby="ws-modal-title">
  <div class="absolute inset-0 bg-black/40" data-ws-close></div>

  <div class="relative w-full max-w-[560px] bg-white rounded-xl shadow-xl flex flex-col max-h-[80vh]">
    <div class="flex items-center justify-between px-6 py-4 border-b border-line shrink-0">
      <div>
        <h2 id="ws-modal-title" class="text-[16px] font-semibold text-head">Your workspaces</h2>
        <p class="text-[13px] text-sub mt-0.5">Switch, manage settings, or invite teammates.</p>
      </div>
      <button type="button" data-ws-close class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Close">
        {!! pb_icon('xmark', 18) !!}
      </button>
    </div>

    <div class="overflow-y-auto px-4 py-3">
      @forelse ($switcherGroups as $group)
      <section @class(['mt-4' => ! $loop->first]) aria-label="{{ $group['label'] }}">
        {{-- Only labelled when there is something to tell apart. A single group needs no
             heading: the modal's own title already says whose workspaces these are. --}}
        @if (count($switcherGroups) > 1)
          <h3 class="px-1 pb-2 text-[11px] font-semibold uppercase tracking-wide text-faint">{{ $group['label'] }}</h3>
        @endif

        <div class="space-y-2">
        @foreach ($group['workspaces'] as $ws)
        @php $avatarColor = $wsColors[$wsIndex++ % count($wsColors)]; @endphp
        @php $memberLabel = $ws['members'].' '.\Illuminate\Support\Str::plural('Member', $ws['members']); @endphp

        @if ($ws['current'])
          <div class="flex items-center gap-3 border border-brand/40 bg-sel/40 rounded-lg px-3 py-2.5">
            <span class="h-9 w-9 rounded-md text-white grid place-items-center text-[13px] font-semibold shrink-0" style="background: {{ $avatarColor }}">{{ $ws['initial'] }}</span>
            <div class="min-w-0 flex-1">
              <div class="text-[14px] font-medium text-ink truncate">{{ $ws['name'] }}
                <span class="text-[11px] bg-sel text-brand rounded px-1.5 py-0.5 ml-2">Current</span>
              </div>
              <div class="text-[12px] text-sub">{{ $ws['role'] }} &bull; {{ $memberLabel }}</div>
            </div>
          </div>
        @else
          <form method="POST" action="{{ $ws['switch_url'] }}" class="block">
            @csrf
            <button type="submit" class="w-full text-left flex items-center gap-3 border border-line rounded-lg px-3 py-2.5 hover:bg-hover hover:border-brand/40 transition-colors">
              <span class="h-9 w-9 rounded-md text-white grid place-items-center text-[13px] font-semibold shrink-0" style="background: {{ $avatarColor }}">{{ $ws['initial'] }}</span>
              <div class="min-w-0 flex-1">
                <div class="text-[14px] font-medium text-ink truncate">{{ $ws['name'] }}</div>
                <div class="text-[12px] text-sub">{{ $ws['role'] }} &bull; {{ $memberLabel }}</div>
              </div>
              <span class="text-[12px] text-link font-medium shrink-0">Switch</span>
            </button>
          </form>
        @endif
        @endforeach
        </div>
      </section>
      @empty
        <p class="px-3 py-6 text-center text-[13px] text-sub">You don't belong to any workspace yet.</p>
      @endforelse
    </div>

    <div class="px-6 py-4 border-t border-line shrink-0">
      <a href="{{ route('workspaces.create') }}" class="w-full inline-flex items-center justify-center gap-2 h-9 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">
        {!! pb_icon('plus', 16) !!}
        Create workspace
      </a>
    </div>
  </div>
</div>

<script>
  (function () {
    var modal = document.getElementById('ws-modal');
    if (!modal || modal.dataset.wired) return;
    modal.dataset.wired = '1';

    function open() { modal.classList.remove('hidden'); }
    function close() { modal.classList.add('hidden'); }

    // Delegated, so any opener anywhere on the page works — including markup rendered after
    // this script ran (Vue-mounted screens mount their chrome later).
    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-ws-open]')) { e.preventDefault(); open(); }
    });

    modal.addEventListener('click', function (e) { if (e.target.closest('[data-ws-close]')) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  })();
</script>
