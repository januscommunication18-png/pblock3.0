{{-- Shared application topbar (workspace switcher · search · actions · account menu).
     Included by every app shell so all pages share the same chrome.
     Resolves $user/$workspace defensively so it never errors on a page that
     didn't explicitly pass them. --}}
@php($__u = $user ?? auth()->user())
@php($__ws = $workspace ?? optional($__u)->currentWorkspace)

{{-- Hidden sign-out form (POST /logout) triggered from the account menu. --}}
<form method="POST" action="{{ route('logout') }}" id="logout-form" class="hidden">@csrf</form>

<header class="h-14 shrink-0 border-b border-line flex items-center gap-2 px-3 sm:px-4">
  {{-- Mobile only: opens the sidebar as a drawer. On desktop the way back from a collapsed
       sidebar lives at the head of the page's own title row — see partials/sidebar-expand. --}}
  <button id="open-sidebar" title="Open navigation" aria-label="Open navigation" aria-controls="sidebar"
          class="lg:hidden h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
    {!! pb_icon('bars', 18) !!}
  </button>

  {{-- Workspace switcher. Both openers raise the shared modal included below, so switching
       happens in place on whatever screen the user is on instead of bouncing to /welcome. --}}
  <div class="flex items-center gap-2 shrink-0">
    <button type="button" data-ws-open title="Switch workspace" class="flex items-center gap-2 px-2 h-9 rounded-md hover:bg-hover">
      <span class="h-6 w-6 rounded-md bg-slate-700 text-white grid place-items-center text-[11px] font-semibold">{{ $__ws->initial() }}</span>
      <span class="font-medium text-[13px] max-w-[110px] sm:max-w-[150px] truncate">{{ $__ws->name }}</span>
    </button>
    <button type="button" data-ws-open class="inline-flex items-center h-7 px-2.5 rounded-md border border-brand text-[12px] text-brand hover:bg-hover whitespace-nowrap">Switch workspace</button>
  </div>

  {{-- Search --}}
  <div class="flex-1 flex justify-center px-2">
    <div class="relative w-full max-w-md">
      {!! pb_icon('magnifying-glass', 15, 'absolute left-3 top-1/2 -translate-y-1/2 text-faint') !!}
      <input type="search" id="pb-topbar-search" name="q" autocomplete="off" placeholder="Search" class="w-full h-9 rounded-md bg-hover pl-9 pr-3 text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />
    </div>
  </div>

  {{-- Actions --}}
  <div class="flex items-center gap-1.5 shrink-0">
    <a href="{{ route('welcome') }}" class="hidden sm:inline-flex items-center h-8 px-3 rounded-md border border-line text-[13px] text-ink hover:bg-hover">Get started</a>
    <a href="{{ route('settings.general') }}" class="hidden sm:inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-line text-[13px] text-ink hover:bg-hover">
      {!! pb_icon('grid', 15) !!}
      Workspace
    </a>
    <button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Inbox">{!! pb_icon('inbox', 17) !!}</button>
    <button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Help">{!! pb_icon('circle-question', 17) !!}</button>

    {{-- Account menu --}}
    <div class="relative ml-1">
      <button id="user-btn" class="h-7 w-7 rounded-full bg-emerald-500 grid place-items-center text-white text-[11px] font-bold ring-2 ring-transparent focus:outline-none">{{ $__u->initial() }}</button>

      <div id="user-menu" class="hidden absolute right-0 top-full mt-2 w-64 bg-white border border-line rounded-xl shadow-lg z-50 p-1.5">
        <div class="relative rounded-lg overflow-hidden px-4 pt-7 pb-4 text-center" style="background-color:#9ca3af;">
          <span class="h-14 w-14 rounded-full bg-brand text-white grid place-items-center text-[20px] font-semibold mx-auto shadow-sm">{{ $__u->initial() }}</span>
          <div class="text-[14px] font-semibold text-white mt-2.5 drop-shadow-sm">{{ $__u->displayName() }}</div>
          <div class="text-[12px] text-white/90 drop-shadow-sm">{{ $__u->email }}</div>
        </div>
        <div class="pt-1.5">
          <a href="{{ route('projects.index') }}?create=1" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
            {!! pb_icon('plus', 16, 'text-sub') !!}
            New project
          </a>
          <a href="{{ route('workspaces.create') }}" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
            {!! pb_icon('grid', 16, 'text-sub') !!}
            Create workspace
          </a>
          <a href="{{ route('settings.general') }}" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
            {!! pb_icon('gear', 16, 'text-sub') !!}
            Workspace settings
          </a>
          <button type="submit" form="logout-form" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
            {!! pb_icon('right-from-bracket', 16, 'text-sub') !!}
            Sign out
          </button>
        </div>
      </div>
    </div>
  </div>
</header>

@include('partials.workspace-switcher')

<script>
  (function () {
    var btn = document.getElementById('user-btn');
    var menu = document.getElementById('user-menu');
    if (!btn || !menu || btn.dataset.wired) return;
    btn.dataset.wired = '1';
    btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('hidden'); });
    document.addEventListener('click', function (e) {
      if (!menu.classList.contains('hidden') && !menu.contains(e.target) && !btn.contains(e.target)) menu.classList.add('hidden');
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') menu.classList.add('hidden'); });
  })();
</script>
