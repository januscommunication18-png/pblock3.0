{{-- Help Desk › Settings › Inboxes › New Email Address (Inbound Email requirements §4, §5, §6).

     A FULL PAGE, and that is the requirement rather than a preference: connecting an address
     means leaving to configure a forwarding rule in another system and coming back, so this
     screen has to survive a refresh, a bookmark and the Back button — none of which a modal
     does. §4 replaced the modal with this URL. --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>New email address — {{ $inboxModel->name }} — Help Desk — {{ $workspace->name }}</title>

  <link rel="stylesheet" href="{{ pb_asset('assets/css/tailwind.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/inter.css') }}" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  {!! pb_icon_styles() !!}
  {!! pb_icon_boot() !!}
  <script defer src="{{ pb_asset('assets/js/icons.js') }}"></script>

  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/help-desk/email-address-new.js') }}"></script>
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  @include('partials.app-topbar')

  <div class="flex-1 flex min-h-0 relative">
    @include('partials.app-sidebar')

    <main class="flex-1 min-w-0 flex flex-col overflow-hidden">
      <div class="h-12 shrink-0 border-b border-line flex items-center gap-3 px-5 sm:px-8">
        <a href="{{ route('help-desk.inboxes.addresses', $inboxModel) }}" class="text-sub hover:text-ink" title="Back to email addresses">
          {!! pb_icon('arrow-left', 16) !!}
        </a>
        <span class="text-[13px] text-sub">Help Desk</span>
        <span class="text-faint">/</span>
        <a href="{{ route('help-desk.inboxes') }}" class="text-[13px] text-sub hover:text-ink">Inboxes</a>
        <span class="text-faint">/</span>
        <a href="{{ route('help-desk.inboxes.addresses', $inboxModel) }}" class="text-[13px] text-sub hover:text-ink">{{ $inboxModel->name }}</a>
        <span class="text-faint">/</span>
        <span class="text-[13px] font-medium text-ink">New email address</span>
      </div>

      <div class="flex-1 min-h-0 overflow-y-auto">
        <div id="help-desk-email-address-new-root" data-bootstrap="{{ json_encode($bootstrap) }}">
          <div class="px-5 sm:px-8 py-10 text-[13px] text-sub">Loading…</div>
        </div>
      </div>
    </main>
  </div>

</body>
</html>
