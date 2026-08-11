{{-- Shared application topbar (workspace switcher · search · actions · account menu).
     Included by every app shell so all pages share the same chrome.
     Resolves $user/$workspace defensively so it never errors on a page that
     didn't explicitly pass them. --}}
@php($__u = $user ?? auth()->user())
@php($__ws = $workspace ?? optional($__u)->currentWorkspace)

{{-- Hidden sign-out form (POST /logout) triggered from the account menu. --}}
<form method="POST" action="{{ route('logout') }}" id="logout-form" class="hidden">@csrf</form>

<header class="h-14 shrink-0 border-b border-line flex items-center gap-2 px-3 sm:px-4">
  <button id="open-sidebar" class="lg:hidden h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
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
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="absolute left-3 top-1/2 -translate-y-1/2 text-faint"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      <input type="search" id="pb-topbar-search" name="q" autocomplete="off" placeholder="Search" class="w-full h-9 rounded-md bg-hover pl-9 pr-3 text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />
    </div>
  </div>

  {{-- Actions --}}
  <div class="flex items-center gap-1.5 shrink-0">
    <a href="{{ route('welcome') }}" class="hidden sm:inline-flex items-center h-8 px-3 rounded-md border border-line text-[13px] text-ink hover:bg-hover">Get started</a>
    <a href="{{ route('settings.general') }}" class="hidden sm:inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-line text-[13px] text-ink hover:bg-hover">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>
      Workspace
    </a>
    <button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Inbox"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M3 13h4l2 3h6l2-3h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 6h14l2 7v4a1 1 0 01-1 1H4a1 1 0 01-1-1v-4l2-7z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></button>
    <button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Help"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M9.5 9.5a2.5 2.5 0 114 2c-1 .7-1.5 1.2-1.5 2.5M12 17.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>

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
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            New project
          </a>
          <a href="{{ route('workspaces.create') }}" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>
            Create workspace
          </a>
          <a href="{{ route('settings.general') }}" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9c.2.62.78 1.04 1.43 1.05H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/></svg>
            Workspace settings
          </a>
          <button type="submit" form="logout-form" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><path d="M9 21H6a2 2 0 01-2-2V5a2 2 0 012-2h3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
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
