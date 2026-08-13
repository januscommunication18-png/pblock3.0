{{-- The account button and its menu — Settings · My Preference · Sign Off.

     ONE copy, on purpose. This markup used to be written out three times: in app-topbar, and
     again in app/projects and app/welcome, which render their own headers rather than including
     the shared one. The three had already drifted — different gear icons (`gear`, `gear-cog`,
     `gear-simple`) and different item lists — and when the menu was updated in app-topbar, the
     projects screen, which is where you land, went on showing the old one.

     Same lesson as partials/work-item-assets: a thing that appears on several pages is one
     partial, or it is several things that look alike until they don't.

     Expects a signed-in user; resolves it defensively so a page that passes `$user`, a page
     that passes `$__u`, and a page that passes neither all work. Requires `#logout-form` on the
     page — every shell that includes this already has one.

     The modal comes WITH the menu, at the bottom of this file. Including it separately is how
     a page ends up with a Settings item that opens nothing. --}}

{{-- Computed once, here, and reused by partials.account-modal below — which is why this file
     builds it rather than each of them calling the controller. The menu's own header is the
     person's card: it shows the cover and the photo they set, not a fixed grey block and their
     initial. Rendered server-side so it is already right on first paint, then kept current by
     profile.js after a save without a reload. --}}
@php
  $__acct = $__u ?? $user ?? auth()->user();
  $__accountProfile = \App\Http\Controllers\Account\ProfileController::payload($__acct);

  // The banner: the uploaded image if there is one, else the chosen gradient, else grey.
  $__acctCover = $__accountProfile['cover_url']
      ? "background:url('".e($__accountProfile['cover_url'])."') center / cover no-repeat"
      : 'background:'.e($__accountProfile['cover_gradient'] ?: '#9ca3af');
@endphp

<div class="relative ml-1">
  <button id="user-btn"
          class="h-7 w-7 rounded-full bg-emerald-500 grid place-items-center text-white text-[11px] font-bold ring-2 ring-transparent focus:outline-none bg-cover bg-center"
          @if ($__accountProfile['avatar_url']) style="background-image:url('{{ $__accountProfile['avatar_url'] }}')" @endif
          aria-label="Account menu">{{ $__accountProfile['avatar_url'] ? '' : $__acct->initial() }}</button>

  <div id="user-menu" class="hidden absolute right-0 top-full mt-2 w-64 bg-white border border-line rounded-xl shadow-lg z-50 p-1.5">
    <div id="user-menu-cover" class="relative rounded-lg overflow-hidden px-4 pt-7 pb-4 text-center" style="{{ $__acctCover }}">
      <span id="user-menu-avatar"
            class="h-14 w-14 rounded-full bg-brand text-white grid place-items-center text-[20px] font-semibold mx-auto shadow-sm bg-cover bg-center ring-2 ring-white/70"
            @if ($__accountProfile['avatar_url']) style="background-image:url('{{ $__accountProfile['avatar_url'] }}')" @endif
      >{{ $__accountProfile['avatar_url'] ? '' : $__acct->initial() }}</span>
      <div id="user-menu-name" class="text-[14px] font-semibold text-white mt-2.5 drop-shadow-sm">{{ $__acct->displayName() }}</div>
      <div class="text-[12px] text-white/90 drop-shadow-sm">{{ $__acct->email }}</div>
    </div>

    {{-- Settings and My Preference open the account modal on their own tab; Sign Off posts the
         hidden form the surrounding page provides.

         The New project, Create workspace and Workspace settings entries that used to sit here
         were removed deliberately, not lost: all three are reachable from the sidebar and the
         workspace switcher, so this menu is now about the PERSON and the chrome around it is
         about the workspace. --}}
    <div class="pt-1.5">
      <button type="button" data-account-open="profile" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
        {!! pb_icon('gear', 16, 'text-sub') !!}
        Settings
      </button>
      <button type="button" data-account-open="preference" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
        {!! pb_icon('sliders', 16, 'text-sub') !!}
        My Preference
      </button>
      <button type="submit" form="logout-form" class="w-full text-left flex items-center gap-2.5 px-2.5 h-9 rounded-lg text-[13px] text-ink hover:bg-hover">
        {!! pb_icon('right-from-bracket', 16, 'text-sub') !!}
        Sign Off
      </button>
    </div>
  </div>
</div>

{{-- The dialog the two items above open. --}}
@include('partials.account-modal')
