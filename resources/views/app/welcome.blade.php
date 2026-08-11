@extends('layouts.app')
@section('title', 'Get started — Project Block')

@section('body')
  {{-- Hidden sign-out form (POST /logout) triggered from the menus below --}}
  <form method="POST" action="{{ route('logout') }}" id="logout-form" class="hidden">@csrf</form>

  <!-- ============ AppTopbar ============ -->
  <header class="h-14 shrink-0 border-b border-line flex items-center gap-2 px-3 sm:px-4">
    <button id="open-sidebar" class="lg:hidden h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>

    <!-- WorkspaceSwitcher -->
    <div class="flex items-center gap-2 shrink-0">
      <span class="flex items-center gap-2 px-2 h-9">
        <span class="h-6 w-6 rounded-md bg-slate-700 text-white grid place-items-center text-[11px] font-semibold">{{ $workspace->initial() }}</span>
        <span class="font-medium text-[13px] max-w-[110px] sm:max-w-[150px] truncate">{{ $workspace->name }}</span>
      </span>
      <button type="button" data-ws-open class="inline-flex items-center h-7 px-2.5 rounded-md border border-brand text-[12px] text-brand hover:bg-hover whitespace-nowrap">Switch workspace</button>
    </div>

    <!-- Search -->
    <div class="flex-1 flex justify-center px-2">
      <div class="relative w-full max-w-md">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="absolute left-3 top-1/2 -translate-y-1/2 text-faint"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <input type="text" placeholder="Search" class="w-full h-9 rounded-md bg-hover pl-9 pr-3 text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />
      </div>
    </div>

    <!-- Actions -->
    <div class="flex items-center gap-1.5 shrink-0">
      <button class="hidden sm:inline-flex items-center h-8 px-3 rounded-md border border-line text-[13px] text-ink hover:bg-hover">Get started</button>
      <button type="button" data-ws-open class="hidden sm:inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-line text-[13px] text-ink hover:bg-hover">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>
        Workspace
      </button>
      <button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Inbox"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M3 13h4l2 3h6l2-3h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 6h14l2 7v4a1 1 0 01-1 1H4a1 1 0 01-1-1v-4l2-7z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></button>
      <button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Help"><svg width="17" height="17" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M9.5 9.5a2.5 2.5 0 114 2c-1 .7-1.5 1.2-1.5 2.5M12 17.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
      <!-- UserMenu -->
      <div class="relative ml-1">
        <button id="user-btn" class="h-7 w-7 rounded-full bg-emerald-500 grid place-items-center text-white text-[11px] font-bold ring-2 ring-transparent focus:outline-none">{{ $user->initial() }}</button>

        <div id="user-menu" class="hidden absolute right-0 top-full mt-2 w-64 bg-white border border-line rounded-xl shadow-lg z-50 p-1.5">
          <div class="relative rounded-lg overflow-hidden px-4 pt-7 pb-4 text-center" style="background-color:#9ca3af;">
            <span class="h-14 w-14 rounded-full bg-brand text-white grid place-items-center text-[20px] font-semibold mx-auto shadow-sm">{{ $user->initial() }}</span>
            <div class="text-[14px] font-semibold text-white mt-2.5 drop-shadow-sm">{{ $user->displayName() }}</div>
            <div class="text-[12px] text-white/90 drop-shadow-sm">{{ $user->email }}</div>
          </div>
          <div class="pt-1.5">
            <a href="{{ route('projects.index') }}?create=1" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>
              New project
            </a>
            <a href="{{ route('workspaces.create') }}" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
              Create workspace
            </a>
            <a href="{{ route('settings.general') }}" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" class="text-sub"><path d="M10.3 4.3a1 1 0 011.4 0l.6.6a1 1 0 001 .24l.9-.3a1 1 0 011.3.9v.9a1 1 0 00.6.9l.8.4a1 1 0 01.4 1.4l-.5.7a1 1 0 000 1l.5.7a1 1 0 01-.4 1.4l-.8.4a1 1 0 00-.6.9v.9a1 1 0 01-1.3.9l-.9-.3a1 1 0 00-1 .24l-.6.6a1 1 0 01-1.4 0" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="12" r="2.4" stroke="currentColor" stroke-width="1.5"/></svg>
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

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <!-- MAIN -->
    <main class="flex-1 min-w-0 overflow-y-auto">
      <div class="flex items-center gap-2 text-[13px] text-sub px-5 sm:px-8 h-11 border-b border-line">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-amber-500"><path d="M9 18h6M10 21h4M12 3a6 6 0 00-4 10.5c.6.6 1 1.3 1 2.5h6c0-1.2.4-1.9 1-2.5A6 6 0 0012 3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        Get started
      </div>

      <div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">
        <h1 class="text-[24px] sm:text-[26px] font-bold text-head">Hey {{ $user->displayName() }}, welcome aboard! 👋</h1>
        <p class="text-[15px] text-sub mt-1">Here's everything you need to kickstart your journey with Project Block.</p>

        <div class="mt-6 border border-line rounded-xl p-5">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" class="text-brand mb-3"><path d="M6 3h12l3 5-9 13L3 8l3-5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
          <div class="flex items-center gap-2 flex-wrap">
            <h3 class="text-[15px] font-semibold text-head">Your 14-day Business plan trial is live!</h3>
            <span class="text-[12px] bg-amber-100 text-amber-700 rounded-md px-2 py-0.5">Trial ends in 13 days</span>
          </div>
          <p class="text-[13px] text-sub mt-1">Explore all Business features. When you're ready, choose to subscribe. You'll not be billed automatically.</p>
          <div class="flex flex-wrap gap-3 mt-4">
            <button class="h-9 px-4 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold">Start subscription</button>
            <button class="h-9 px-4 rounded-md border border-stroke hover:bg-hover text-ink text-[13px] font-semibold">Explore Business features</button>
          </div>
        </div>

        <h2 class="text-[18px] font-bold text-head mt-8">Get started</h2>
        <p class="text-[14px] text-sub mb-4">Begin your setup and see your ideas come to life faster.</p>
        <div id="checklist" class="border border-line rounded-xl divide-y divide-line overflow-hidden"></div>

        <h2 class="text-[18px] font-bold text-head mt-8">Get your team on board</h2>
        <p class="text-[14px] text-sub mb-4">Invite teammates and start collaborating in your workspace.</p>
        <div class="border border-line rounded-xl p-5 flex items-center gap-4">
          <span class="h-10 w-10 rounded-full bg-hover grid place-items-center text-sub shrink-0"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.7"/><path d="M3 19a6 6 0 0112 0M16 6a3 3 0 010 6M18 19a6 6 0 00-3-5.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>
          <div class="min-w-0">
            <div class="text-[14px] font-medium text-ink">Invite your team</div>
            <p class="text-[13px] text-sub">Work is better together — bring your teammates in.</p>
          </div>
          <a href="{{ route('workspaces.invite') }}" class="ml-auto h-9 px-4 grid place-items-center rounded-md border border-stroke hover:bg-hover text-ink text-[13px] font-semibold shrink-0">Invite</a>
        </div>
        <div class="h-8"></div>
      </div>
    </main>
  </div>

  @include('partials.workspace-switcher')

  @if (session('status'))
    <div class="fixed top-4 right-4 z-[80] w-full max-w-sm flex justify-end pointer-events-none">
      <div id="toast" class="pointer-events-auto w-full max-w-sm overflow-hidden rounded-lg bg-white shadow-lg ring-1 ring-black/5">
        <div class="p-4">
          <div class="flex items-start">
            <div class="shrink-0"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" fill="#22c55e"/><path d="M8 12l2.5 2.5L16 9" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
            <div class="ml-3 w-0 flex-1 pt-0.5">
              <p class="text-[13px] font-semibold text-head">Success</p>
              <p class="mt-1 text-[13px] text-sub">{{ session('status') }}</p>
            </div>
            <div class="ml-4 flex shrink-0">
              <button type="button" onclick="document.getElementById('toast').remove()" class="inline-flex rounded-md text-faint hover:text-sub">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  @endif

  <script>
    // Mobile sidebar drawer
    var sb = document.getElementById('sidebar');
    var bd = document.getElementById('sidebar-backdrop');
    document.getElementById('open-sidebar').addEventListener('click', function () { sb.classList.remove('-translate-x-full'); bd.classList.remove('hidden'); });
    document.getElementById('close-sidebar').addEventListener('click', function () { sb.classList.add('-translate-x-full'); bd.classList.add('hidden'); });
    bd.addEventListener('click', function () { sb.classList.add('-translate-x-full'); bd.classList.add('hidden'); });

    // Get-started checklist (cosmetic for now)
    var TASKS = ['Create a project', 'Create a work item', 'Invite team members', 'Try creating a page', 'Create a view'];
    document.getElementById('checklist').innerHTML = TASKS.map(function (t, i) {
      return '<label class="flex items-center gap-3 px-4 h-12 cursor-pointer hover:bg-hover">' +
        '<input type="checkbox" class="pb-check" data-task="' + i + '" />' +
        '<span class="text-[14px] text-ink">' + t + '</span></label>';
    }).join('');
    document.getElementById('checklist').addEventListener('change', function (e) {
      if (!e.target.matches('input[data-task]')) return;
      var span = e.target.nextElementSibling;
      span.classList.toggle('line-through', e.target.checked);
      span.classList.toggle('text-faint', e.target.checked);
    });

    // User menu
    var userBtn = document.getElementById('user-btn');
    var userMenu = document.getElementById('user-menu');
    userBtn.addEventListener('click', function (e) { e.stopPropagation(); userMenu.classList.toggle('hidden'); });
    document.addEventListener('click', function (e) {
      if (!userMenu.classList.contains('hidden') && !userMenu.contains(e.target) && !userBtn.contains(e.target)) userMenu.classList.add('hidden');
    });

    // Auto-dismiss toast
    var toast = document.getElementById('toast');
    if (toast) setTimeout(function () { toast.style.transition = 'opacity .4s'; toast.style.opacity = '0'; setTimeout(function () { toast.remove(); }, 400); }, 4000);
  </script>
@endsection
