<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Help Desk — {{ $workspace->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      <div class="flex-1 min-h-0 overflow-y-auto">
        <div class="max-w-[720px] mx-auto px-6 py-16 text-center">
          <div class="mx-auto h-12 w-12 rounded-xl bg-hover grid place-items-center text-sub">
            {!! pb_icon('inbox', 22) !!}
          </div>

          <h1 class="mt-4 text-[18px] font-semibold text-head">Help Desk</h1>

          {{-- Says plainly what is and is not here yet. An empty screen that pretends to be
               finished sends people looking for controls that do not exist. --}}
          <p class="mt-2 text-[13px] text-sub">
            Set up who works in {{ $workspace->name }}'s Help Desk and which inboxes they can open.
            Conversations, email channels and routing arrive in the next release.
          </p>

          {{-- Where you stand, in your own words. "Help Desk role" and "workspace role" are
               different things (§5), and the one that decides what you can do in here is this
               one — so a workspace admin who is not a member is told exactly that. --}}
          <div class="mt-6 inline-flex items-center gap-2 rounded-full border border-line px-3 h-8 text-[12px]">
            @if ($member && $member->isActive())
              <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
              <span class="text-sub">Your Help Desk role:</span>
              <span class="font-semibold text-ink">{{ $roleLabel }}</span>
            @elseif ($member)
              <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
              <span class="text-sub">Your Help Desk membership is deactivated</span>
            @else
              <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
              <span class="text-sub">You are not a Help Desk member</span>
            @endif
          </div>

          @if ($canManage)
            <div class="mt-6 flex items-center justify-center gap-2">
              <a href="{{ route('help-desk.members') }}"
                 class="inline-flex items-center h-9 px-4 rounded-md bg-brand text-white text-[13px] font-semibold hover:opacity-90">
                Manage members
              </a>
              <a href="{{ route('settings.general') }}"
                 class="inline-flex items-center h-9 px-4 rounded-md border border-stroke text-[13px] font-semibold text-ink hover:bg-hover">
                Workspace settings
              </a>
            </div>

            <p class="mt-4 text-[12px] text-faint">
              {{ $memberCount }} {{ Str::plural('member', $memberCount) }} ·
              {{ $inboxCount }} {{ Str::plural('inbox', $inboxCount) }}
            </p>
          @endif

          <p class="mt-8 text-[12px] text-faint max-w-md mx-auto">
            Help Desk has its own membership: turning the app on does not give workspace members
            access to it, and a Help Desk role grants nothing outside the Help Desk.
          </p>
        </div>
      </div>
    </main>
  </div>

</body>
</html>
