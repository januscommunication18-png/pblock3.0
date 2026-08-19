{{-- The Help Desk's own sidebar (docs/features/help-desk.md, FR-1.2).

     Replaces the sidebar BODY while inside /help-desk, and only the body: the rail, the
     workspace header, the collapse control and the drawer behaviour are the same panel, so they
     stay in app-sidebar.blade.php rather than being copied into a second one that would drift.
     This is the pattern Wiki already uses.

     Projects, the workspace group, Inbox, Drafts and Stickies are deliberately absent. The Help
     Desk is a different room, not the same room with extra doors — and a support agent who is
     not a project contributor has no business being offered a project list. --}}
@php($__hd = app(\App\Services\HelpDesk\HelpDeskAccess::class))
@php($__hdInboxes = $__hd->visibleInboxes(auth()->user(), $__ws))
@php($__hdCanManage = $__hd->canAdminister(auth()->user(), $__ws))
@php($__hdSpaces = $__hd->visibleSpaces(auth()->user(), $__ws))
@php($__hdSpaceId = app(\App\Services\HelpDesk\HelpDeskSpaceContext::class)->current(auth()->user(), $__ws))
@php($__hdSpace = collect($__hdSpaces)->firstWhere('id', $__hdSpaceId))
@php($__hdSpaceInboxes = $__hdSpaceId
       ? \App\Models\HelpDeskInbox::query()->where('help_desk_space_id', $__hdSpaceId)->pluck('id')->all()
       : null)

{{-- The space switcher (Workspace & Inbox Assignment §13).

     At the top of the Help Desk's own navigation, because it says where you are rather than
     what you are looking at: choosing Partner Support narrows the inboxes below, the
     conversation list and everything else scoped to a space.

     A form rather than links: switching writes the choice for every subsequent screen, and a GET
     that changes state is a GET a browser is entitled to prefetch. `return` brings you back to
     the page you were on — SpaceController only ever honours a path inside /help-desk. --}}
@if (count($__hdSpaces) > 0)
  <details class="mb-2">
    <summary class="list-none [&::-webkit-details-marker]:hidden flex items-center gap-2 px-2 h-9 rounded-md hover:bg-hover cursor-pointer">
      <span class="h-6 w-6 shrink-0 rounded-md grid place-items-center text-white text-[11px] font-semibold"
            style="background-color: {{ $__hdSpace['color'] ?? '#64748B' }}">
        {{ $__hdSpace['initial'] ?? '∗' }}
      </span>
      <span class="truncate text-[13px] font-medium text-ink">{{ $__hdSpace['name'] ?? 'All spaces' }}</span>
      {!! pb_icon('chevron-down', 14, 'pb-chev ml-auto text-faint transition-transform') !!}
    </summary>

    <form method="POST" action="{{ route('help-desk.spaces.switch') }}" class="mt-0.5 space-y-0.5">
      @csrf
      <input type="hidden" name="return" value="{{ request()->fullUrl() }}" />

      <button type="submit" name="space" value=""
              @class(['w-full text-left flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-ink hover:bg-hover', 'bg-sel text-brand' => $__hdSpaceId === null])>
        <span class="text-[12px]">All spaces</span>
      </button>

      @foreach ($__hdSpaces as $__hdOption)
        <button type="submit" name="space" value="{{ $__hdOption['id'] }}"
                @class(['w-full text-left flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-ink hover:bg-hover', 'bg-sel text-brand' => $__hdSpaceId === $__hdOption['id']])>
          <span class="h-4 w-4 shrink-0 rounded grid place-items-center text-white text-[9px] font-semibold"
                style="background-color: {{ $__hdOption['color'] ?? '#64748B' }}">{{ $__hdOption['initial'] }}</span>
          <span class="truncate text-[12px]">{{ $__hdOption['name'] }}</span>
        </button>
      @endforeach

      @if ($__hdCanManage)
        <a href="{{ route('help-desk.spaces.create') }}"
           class="flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-sub hover:bg-hover text-[12px]">
          {!! pb_icon('plus', 13, 'shrink-0') !!} Create space
        </a>
      @endif
    </form>
  </details>
@endif

<a href="{{ route('help-desk.index') }}"
   @class(['flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover', 'bg-sel text-brand' => request()->routeIs('help-desk.index')])>
  {!! pb_icon('house', 15) !!}Overview
</a>
<a href="{{ route('help-desk.conversations') }}"
   @class(['flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover', 'bg-sel text-brand' => request()->routeIs('help-desk.conversations')])>
  {!! pb_icon('inbox', 15) !!}Conversations
</a>
{{-- Spaces is a primary section, not a setting (§2): it is how somebody running three support
     operations moves between them, which is navigation rather than configuration. Creating and
     editing one is still administration — the screen decides that, not this link. --}}
<a href="{{ route('help-desk.spaces') }}"
   @class(['flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover', 'bg-sel text-brand' => request()->routeIs('help-desk.spaces*')])>
  {!! pb_icon('grid', 15) !!}Spaces
</a>

{{-- Inboxes — what this person may open, and nothing they may not (FR-1.7), narrowed to the
     space in context when there is one (§13). Rows are plain until conversations exist: a link
     to a screen that has not been built yet is worse than no link. --}}
@php($__hdShown = $__hdSpaceInboxes === null
       ? $__hdInboxes
       : array_values(array_filter($__hdInboxes, fn ($i) => in_array($i['id'], $__hdSpaceInboxes, true))))
<details open class="mt-3">
  <summary class="list-none [&::-webkit-details-marker]:hidden flex items-center gap-1 px-2 h-8 rounded-md hover:bg-hover cursor-pointer">
    <span class="text-[11px] font-semibold text-faint uppercase tracking-wide">Inboxes</span>
    {!! pb_icon('chevron-down', 14, 'pb-chev ml-auto text-faint transition-transform') !!}
  </summary>
  <div class="mt-0.5 space-y-0.5">
    @forelse ($__hdShown as $__hdInbox)
      <div class="flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-ink">
        {!! pb_icon('inbox', 14, 'text-sub shrink-0') !!}
        <span class="truncate">{{ $__hdInbox['name'] }}</span>
      </div>
    @empty
      <div class="px-2 h-8 flex items-center text-[12px] text-faint">
        @if ($__hdSpace)
          {{ 'None in '.$__hdSpace['name'] }}
        @else
          {{ $__hdCanManage ? 'None assigned to you' : 'No inboxes yet' }}
        @endif
      </div>
    @endforelse
  </div>
</details>

@if ($__hdCanManage)
  <details open class="mt-1">
    <summary class="list-none [&::-webkit-details-marker]:hidden flex items-center gap-1 px-2 h-8 rounded-md hover:bg-hover cursor-pointer">
      <span class="text-[11px] font-semibold text-faint uppercase tracking-wide">Settings</span>
      {!! pb_icon('chevron-down', 14, 'pb-chev ml-auto text-faint transition-transform') !!}
    </summary>
    <div class="mt-0.5 space-y-0.5">
      <a href="{{ route('help-desk.inboxes') }}"
         @class(['flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-ink hover:bg-hover', 'bg-sel text-brand' => request()->routeIs('help-desk.inboxes')])>
        {!! pb_icon('inbox', 14, 'text-sub shrink-0') !!}
        Inboxes
      </a>
      <a href="{{ route('help-desk.members') }}"
         @class(['flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-ink hover:bg-hover', 'bg-sel text-brand' => request()->routeIs('help-desk.members')])>
        {!! pb_icon('users', 14, 'text-sub shrink-0') !!}
        Members
      </a>
      <a href="{{ route('help-desk.activity') }}"
         @class(['flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-ink hover:bg-hover', 'bg-sel text-brand' => request()->routeIs('help-desk.activity')])>
        {!! pb_icon('clock', 14, 'text-sub shrink-0') !!}
        Activity
      </a>
    </div>
  </details>
@endif

<div class="mt-3 mx-2 rounded-md border border-line bg-hover px-3 py-2.5">
  <p class="text-[12px] text-sub">
    Conversations, email channels and routing are being built. Help Desk membership is separate
    from workspace membership.
  </p>
</div>
