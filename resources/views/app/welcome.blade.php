@extends('layouts.app')
@section('title', 'Get started — Project Block')

@section('body')
  {{-- Hidden sign-out form (POST /logout) triggered from the menus below --}}
  <form method="POST" action="{{ route('logout') }}" id="logout-form" class="hidden">@csrf</form>

  <!-- ============ AppTopbar ============ -->
  <header class="h-14 shrink-0 border-b border-line flex items-center gap-2 px-3 sm:px-4">
    <button id="open-sidebar" class="lg:hidden h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover">
      {!! pb_icon('bars', 18) !!}
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
        {!! pb_icon('magnifying-glass', 15, 'absolute left-3 top-1/2 -translate-y-1/2 text-faint') !!}
        <input type="text" placeholder="Search" class="w-full h-9 rounded-md bg-hover pl-9 pr-3 text-[13px] text-ink placeholder:text-faint outline outline-1 -outline-offset-1 outline-transparent focus:bg-white focus:outline-stroke" />
      </div>
    </div>

    <!-- Actions -->
    <div class="flex items-center gap-1.5 shrink-0">
      <button class="hidden sm:inline-flex items-center h-8 px-3 rounded-md border border-line text-[13px] text-ink hover:bg-hover">Get started</button>
      <button type="button" data-ws-open class="hidden sm:inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-line text-[13px] text-ink hover:bg-hover">
        {!! pb_icon('grid', 15) !!}
        Workspace
      </button>
      <button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Inbox">{!! pb_icon('inbox', 17) !!}</button>
      <button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Help">{!! pb_icon('circle-question', 17) !!}</button>
      <!-- UserMenu -->
      {{-- Account menu — the shared partial. This screen renders its own header, so it
           opts in here rather than inheriting partials.app-topbar. --}}
      @include('partials.account-menu')

    </div>
  </header>

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <!-- MAIN -->
    <main class="flex-1 min-w-0 overflow-y-auto">
      <div class="flex items-center gap-2 text-[13px] text-sub px-5 sm:px-8 h-11 border-b border-line">
        @include('partials.sidebar-expand')
        {!! pb_icon('lightbulb', 15, 'text-amber-500') !!}
        Get started
      </div>

      <div class="max-w-[820px] mx-auto px-5 sm:px-8 py-8">
        <h1 class="text-[24px] sm:text-[26px] font-bold text-head">Hey {{ $user->displayName() }}, welcome aboard! 👋</h1>
        <p class="text-[15px] text-sub mt-1">Here's everything you need to kickstart your journey with Project Block.</p>

        <div class="mt-6 border border-line rounded-xl p-5">
          {!! pb_icon('gem', 22, 'text-brand mb-3') !!}
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
          <span class="h-10 w-10 rounded-full bg-hover grid place-items-center text-sub shrink-0">{!! pb_icon('users-thin', 20) !!}</span>
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
                {!! pb_icon('xmark', 18) !!}
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
