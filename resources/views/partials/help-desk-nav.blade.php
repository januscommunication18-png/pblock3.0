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

<a href="{{ route('help-desk.index') }}"
   @class(['flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover', 'bg-sel text-brand' => request()->routeIs('help-desk.index')])>
  {!! pb_icon('inbox', 15) !!}Overview
</a>

{{-- Inboxes — what this person may open, and nothing they may not (FR-1.7). Rows are plain
     until conversations exist: a link to a screen that has not been built yet is worse than
     no link, and Phase 2 is what gives an inbox somewhere to go. --}}
<details open class="mt-3">
  <summary class="list-none [&::-webkit-details-marker]:hidden flex items-center gap-1 px-2 h-8 rounded-md hover:bg-hover cursor-pointer">
    <span class="text-[11px] font-semibold text-faint uppercase tracking-wide">Inboxes</span>
    {!! pb_icon('chevron-down', 14, 'pb-chev ml-auto text-faint transition-transform') !!}
  </summary>
  <div class="mt-0.5 space-y-0.5">
    @forelse ($__hdInboxes as $__hdInbox)
      <div class="flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-ink">
        {!! pb_icon('inbox', 14, 'text-sub shrink-0') !!}
        <span class="truncate">{{ $__hdInbox['name'] }}</span>
      </div>
    @empty
      <div class="px-2 h-8 flex items-center text-[12px] text-faint">
        {{ $__hdCanManage ? 'None assigned to you' : 'No inboxes yet' }}
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
      <a href="{{ route('help-desk.members') }}"
         @class(['flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-ink hover:bg-hover', 'bg-sel text-brand' => request()->routeIs('help-desk.members')])>
        {!! pb_icon('users', 14, 'text-sub shrink-0') !!}
        Members
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
