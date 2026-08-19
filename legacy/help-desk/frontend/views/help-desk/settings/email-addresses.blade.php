{{-- Help Desk › Settings › Inboxes › Email Addresses (Inbound Email requirements §10).

     The list of addresses forwarded into one inbox, and the inbox's own generated inbound
     address above them. Its own URL, like the page that adds to it — see §4. --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Email addresses — {{ $inboxModel->name }} — Help Desk — {{ $workspace->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/help-desk/email-addresses.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      <div class="h-12 shrink-0 border-b border-line flex items-center gap-3 px-5 sm:px-8">
        <a href="{{ route('help-desk.inboxes') }}" class="text-sub hover:text-ink" title="Back to inboxes">
          {!! pb_icon('arrow-left', 16) !!}
        </a>
        <span class="text-[13px] text-sub">Help Desk</span>
        <span class="text-faint">/</span>
        <a href="{{ route('help-desk.inboxes') }}" class="text-[13px] text-sub hover:text-ink">Inboxes</a>
        <span class="text-faint">/</span>
        <span class="text-[13px] font-medium text-ink">{{ $inboxModel->name }}</span>
      </div>

      <div class="flex-1 min-h-0 overflow-y-auto">
        {{-- §9's success notification, flashed by the store action so it survives the
             navigation back here. Rendered by the page rather than by the component: it is
             about the request that has just finished, not about the list's state. --}}
        @if (session('status'))
          <div class="max-w-[980px] mx-auto px-5 sm:px-8 pt-6">
            <div class="rounded-lg border border-brand/30 bg-brand/5 px-3.5 py-2.5 text-[13px] text-brand">
              {{ session('status') }}
            </div>
          </div>
        @endif

        <div id="help-desk-email-addresses-root" data-bootstrap="{{ json_encode($bootstrap) }}">
          <div class="px-5 sm:px-8 py-10 text-[13px] text-sub">Loading…</div>
        </div>
      </div>
    </main>
  </div>

</body>
</html>
