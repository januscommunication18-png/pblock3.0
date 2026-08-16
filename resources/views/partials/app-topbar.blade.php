{{-- Shared application topbar (workspace switcher · search · actions · account menu).
     Included by every app shell so all pages share the same chrome.
     Resolves $user/$workspace defensively so it never errors on a page that
     didn't explicitly pass them. --}}
@php($__u = $user ?? auth()->user())
@php($__ws = $workspace ?? optional($__u)->currentWorkspace)

@include('partials.realtime')

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
    <a href="{{ route('inbox.index') }}" class="relative h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Inbox" aria-label="Inbox">{!! pb_icon('inbox', 17) !!}
      @php($__inboxCount = auth()->check() ? \App\Models\InboxNotification::query()->for(auth()->id())->unread()->count() : 0)
      {{-- Rendered server-side and then kept current over the socket (§26/§27): the count has
           to move when somebody assigns you something while you are on another screen. --}}
      <span data-inbox-count
            class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 grid place-items-center rounded-full bg-brand text-white text-[10px] font-bold {{ $__inboxCount ? '' : 'hidden' }}">{{ $__inboxCount }}</span>
    </a>
    <button class="h-8 w-8 grid place-items-center rounded-md text-sub hover:bg-hover" title="Help">{!! pb_icon('circle-question', 17) !!}</button>

    {{-- Account menu — one partial, shared with app/projects and app/welcome, which
         render their own headers. --}}
    @include('partials.account-menu')

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
